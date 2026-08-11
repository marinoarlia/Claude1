<?php
declare(strict_types=1);

/**
 * Lettore minimale per veri file Excel 97-2003 .xls (BIFF8/OLE2).
 * Legge il primo foglio e i tipi di cella necessari all'import SKU/ItemID/EAN.
 */
final class XlsReader
{
    private string $data;
    private int $sectorSize = 512;
    private int $miniSectorSize = 64;
    private array $fat = [];
    private array $miniFat = [];
    private string $miniStream = '';

    public function readFirstSheet(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false || strlen($raw) < 512) {
            throw new RuntimeException('File XLS vuoto o non leggibile.');
        }
        if (substr($raw, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw new RuntimeException('Il file non è un vero Excel .xls 97-2003.');
        }
        $this->data = $raw;
        $this->sectorSize = 1 << $this->u16($raw, 30);
        $this->miniSectorSize = 1 << $this->u16($raw, 32);
        $this->loadFat();

        $dirStart = $this->u32($raw, 48);
        $dirData = $this->readChain($dirStart, $this->fat);
        $entries = $this->parseDirectory($dirData);
        $root = null;
        $workbook = null;
        foreach ($entries as $entry) {
            if ($entry['type'] === 5) {
                $root = $entry;
            }
            if ($entry['type'] === 2 && in_array(strtolower($entry['name']), ['workbook', 'book'], true)) {
                $workbook = $entry;
            }
        }
        if (!$root || !$workbook) {
            throw new RuntimeException('Struttura XLS non riconosciuta: stream Workbook mancante.');
        }

        $miniFatStart = $this->u32($raw, 60);
        $miniFatCount = $this->u32($raw, 64);
        if ($miniFatCount > 0 && $miniFatStart < 0xFFFFFFFA) {
            $miniBytes = $this->readChain($miniFatStart, $this->fat, $miniFatCount * $this->sectorSize);
            for ($i = 0, $n = intdiv(strlen($miniBytes), 4); $i < $n; $i++) {
                $this->miniFat[] = $this->u32($miniBytes, $i * 4);
            }
        }
        if ($root['size'] > 0 && $root['start'] < 0xFFFFFFFA) {
            $this->miniStream = substr($this->readChain($root['start'], $this->fat), 0, $root['size']);
        }

        $cutoff = $this->u32($raw, 56);
        if ($workbook['size'] < $cutoff && $this->miniStream !== '') {
            $book = $this->readMiniChain($workbook['start'], $workbook['size']);
        } else {
            $book = substr($this->readChain($workbook['start'], $this->fat), 0, $workbook['size']);
        }
        return $this->parseWorkbook($book);
    }

    private function loadFat(): void
    {
        $difat = [];
        for ($i = 0; $i < 109; $i++) {
            $sid = $this->u32($this->data, 76 + $i * 4);
            if ($sid < 0xFFFFFFFA) {
                $difat[] = $sid;
            }
        }
        $difatSid = $this->u32($this->data, 68);
        $difatCount = $this->u32($this->data, 72);
        for ($d = 0; $d < $difatCount && $difatSid < 0xFFFFFFFA; $d++) {
            $sec = $this->sector($difatSid);
            $per = intdiv($this->sectorSize, 4) - 1;
            for ($i = 0; $i < $per; $i++) {
                $sid = $this->u32($sec, $i * 4);
                if ($sid < 0xFFFFFFFA) {
                    $difat[] = $sid;
                }
            }
            $difatSid = $this->u32($sec, $per * 4);
        }
        foreach ($difat as $fatSid) {
            $sec = $this->sector($fatSid);
            for ($i = 0, $n = intdiv($this->sectorSize, 4); $i < $n; $i++) {
                $this->fat[] = $this->u32($sec, $i * 4);
            }
        }
    }

    private function parseDirectory(string $dir): array
    {
        $out = [];
        for ($off = 0; $off + 128 <= strlen($dir); $off += 128) {
            $nameLen = $this->u16($dir, $off + 64);
            $type = ord($dir[$off + 66]);
            if ($type === 0 || $nameLen < 2) {
                continue;
            }
            $nameRaw = substr($dir, $off, max(0, $nameLen - 2));
            $out[] = [
                'name' => $this->decodeUtf16($nameRaw),
                'type' => $type,
                'start' => $this->u32($dir, $off + 116),
                'size' => $this->u32($dir, $off + 120),
            ];
        }
        return $out;
    }

    private function parseWorkbook(string $book): array
    {
        $records = [];
        $sheetOffset = null;
        $sstChunks = [];
        $inSst = false;
        for ($pos = 0, $len = strlen($book); $pos + 4 <= $len;) {
            $id = $this->u16($book, $pos);
            $size = $this->u16($book, $pos + 2);
            $payload = substr($book, $pos + 4, $size);
            $records[] = [$pos, $id, $payload];
            if ($id === 0x0085 && $sheetOffset === null && strlen($payload) >= 6 && ord($payload[5]) === 0x00) {
                $sheetOffset = $this->u32($payload, 0);
            }
            if ($id === 0x00FC) {
                $sstChunks = [$payload];
                $inSst = true;
            } elseif ($id === 0x003C && $inSst) {
                $sstChunks[] = $payload;
            } elseif ($inSst) {
                $inSst = false;
            }
            $pos += 4 + $size;
        }
        if ($sheetOffset === null) {
            throw new RuntimeException('Primo foglio XLS non trovato.');
        }
        $sst = $sstChunks ? $this->parseSst($sstChunks) : [];
        return $this->parseSheet($book, $sheetOffset, $sst);
    }

    private function parseSst(array $chunks): array
    {
        $c = new XlsChunkCursor($chunks);
        $c->readRaw(8); // total strings + unique strings
        $strings = [];
        while (!$c->eof()) {
            try {
                $cchRaw = $c->readRaw(2);
                if (strlen($cchRaw) < 2) break;
                $cch = unpack('v', $cchRaw)[1];
                $flagsRaw = $c->readRaw(1);
                if ($flagsRaw === '') break;
                $flags = ord($flagsRaw);
                $is16 = ($flags & 0x01) !== 0;
                $richRuns = ($flags & 0x08) ? unpack('v', $c->readRaw(2))[1] : 0;
                $extLen = ($flags & 0x04) ? unpack('V', $c->readRaw(4))[1] : 0;
                $parts = $c->readCharacters($cch, $is16);
                $text = '';
                foreach ($parts as [$bytes, $wide]) {
                    $text .= $wide ? $this->decodeUtf16($bytes) : $this->decodeCompressed($bytes);
                }
                if ($richRuns > 0) $c->readRaw($richRuns * 4);
                if ($extLen > 0) $c->readRaw($extLen);
                $strings[] = $text;
            } catch (RuntimeException $e) {
                break;
            }
        }
        return $strings;
    }

    private function parseSheet(string $book, int $start, array $sst): array
    {
        $cells = [];
        $len = strlen($book);
        for ($pos = $start; $pos + 4 <= $len;) {
            $id = $this->u16($book, $pos);
            $size = $this->u16($book, $pos + 2);
            $p = substr($book, $pos + 4, $size);
            if ($id === 0x000A) break;
            if ($id === 0x00FD && strlen($p) >= 10) { // LABELSST
                $row = $this->u16($p, 0); $col = $this->u16($p, 2); $idx = $this->u32($p, 6);
                $cells[$row][$col] = $sst[$idx] ?? '';
            } elseif ($id === 0x0203 && strlen($p) >= 14) { // NUMBER
                $row = $this->u16($p, 0); $col = $this->u16($p, 2);
                $num = unpack('e', substr($p, 6, 8))[1];
                $cells[$row][$col] = $this->numberToString($num);
            } elseif ($id === 0x027E && strlen($p) >= 10) { // RK
                $row = $this->u16($p, 0); $col = $this->u16($p, 2);
                $cells[$row][$col] = $this->numberToString($this->decodeRk($this->u32($p, 6)));
            } elseif ($id === 0x00BD && strlen($p) >= 10) { // MULRK
                $row = $this->u16($p, 0); $firstCol = $this->u16($p, 2);
                $count = intdiv(strlen($p) - 6, 6);
                for ($i = 0; $i < $count; $i++) {
                    $rk = $this->u32($p, 6 + $i * 6);
                    $cells[$row][$firstCol + $i] = $this->numberToString($this->decodeRk($rk));
                }
            } elseif ($id === 0x0204 && strlen($p) >= 8) { // LABEL (BIFF5/compat)
                $row = $this->u16($p, 0); $col = $this->u16($p, 2); $n = $this->u16($p, 6);
                // In BIFF8 il testo LABEL è XLUnicodeString: dopo cch c'è il flag fHighByte.
                if (strlen($p) >= 9) {
                    $wide = (ord($p[8]) & 0x01) !== 0;
                    $bytes = substr($p, 9, $n * ($wide ? 2 : 1));
                    $cells[$row][$col] = $wide ? $this->decodeUtf16($bytes) : $this->decodeCompressed($bytes);
                } else {
                    $cells[$row][$col] = '';
                }
            }
            $pos += 4 + $size;
        }
        ksort($cells);
        $rows = [];
        foreach ($cells as $r => $cols) {
            $rows[$r + 1] = [
                trim((string)($cols[0] ?? '')),
                trim((string)($cols[1] ?? '')),
                trim((string)($cols[2] ?? '')),
            ];
        }
        return $rows;
    }

    private function readChain(int $start, array $fat, ?int $limit = null): string
    {
        $out = '';
        $sid = $start;
        $seen = [];
        while ($sid < 0xFFFFFFFA && isset($fat[$sid]) && !isset($seen[$sid])) {
            $seen[$sid] = true;
            $out .= $this->sector($sid);
            if ($limit !== null && strlen($out) >= $limit) break;
            $sid = $fat[$sid];
        }
        return $limit === null ? $out : substr($out, 0, $limit);
    }

    private function readMiniChain(int $start, int $size): string
    {
        $out = '';
        $sid = $start;
        $seen = [];
        while ($sid < 0xFFFFFFFA && isset($this->miniFat[$sid]) && !isset($seen[$sid]) && strlen($out) < $size) {
            $seen[$sid] = true;
            $out .= substr($this->miniStream, $sid * $this->miniSectorSize, $this->miniSectorSize);
            $sid = $this->miniFat[$sid];
        }
        return substr($out, 0, $size);
    }

    private function sector(int $sid): string
    {
        return substr($this->data, ($sid + 1) * $this->sectorSize, $this->sectorSize);
    }

    private function decodeRk(int $rk): float
    {
        if ($rk & 0x02) {
            $v = $rk >> 2;
            if ($v & 0x20000000) $v -= 0x40000000;
            $n = (float)$v;
        } else {
            $high = $rk & 0xFFFFFFFC;
            $bytes = pack('V2', 0, $high);
            $n = unpack('e', $bytes)[1];
        }
        return ($rk & 0x01) ? $n / 100 : $n;
    }

    private function numberToString(float $n): string
    {
        if (is_finite($n) && abs($n - round($n)) < 0.0000001) return sprintf('%.0f', $n);
        return rtrim(rtrim(sprintf('%.12F', $n), '0'), '.');
    }

    private function decodeUtf16(string $s): string
    {
        if ($s === '') return '';
        if (function_exists('mb_convert_encoding')) return mb_convert_encoding($s, 'UTF-8', 'UTF-16LE');
        if (function_exists('iconv')) return (string)iconv('UTF-16LE', 'UTF-8//IGNORE', $s);
        return preg_replace('/\x00/', '', $s) ?? '';
    }

    private function decodeCompressed(string $s): string
    {
        if ($s === '' || preg_match('//u', $s)) return $s;
        if (function_exists('mb_convert_encoding')) return mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
        if (function_exists('iconv')) return (string)iconv('ISO-8859-1', 'UTF-8//IGNORE', $s);
        return $s;
    }

    private function u16(string $s, int $o): int { return unpack('v', substr($s, $o, 2))[1]; }
    private function u32(string $s, int $o): int { return unpack('V', substr($s, $o, 4))[1]; }
}

final class XlsChunkCursor
{
    private array $chunks;
    private int $chunk = 0;
    private int $pos = 0;

    public function __construct(array $chunks) { $this->chunks = array_values($chunks); }

    public function eof(): bool
    {
        return $this->chunk >= count($this->chunks) || ($this->chunk === count($this->chunks) - 1 && $this->pos >= strlen($this->chunks[$this->chunk]));
    }

    public function readRaw(int $n): string
    {
        $out = '';
        while ($n > 0 && $this->chunk < count($this->chunks)) {
            $available = strlen($this->chunks[$this->chunk]) - $this->pos;
            if ($available <= 0) { $this->chunk++; $this->pos = 0; continue; }
            $take = min($n, $available);
            $out .= substr($this->chunks[$this->chunk], $this->pos, $take);
            $this->pos += $take; $n -= $take;
        }
        if ($n > 0) throw new RuntimeException('SST troncata.');
        return $out;
    }

    public function readCharacters(int $count, bool $wide): array
    {
        $parts = [];
        $remaining = $count;
        while ($remaining > 0) {
            if ($this->chunk >= count($this->chunks)) throw new RuntimeException('SST caratteri troncati.');
            $available = strlen($this->chunks[$this->chunk]) - $this->pos;
            $bytesPerChar = $wide ? 2 : 1;
            $charsHere = intdiv($available, $bytesPerChar);
            if ($charsHere <= 0) {
                $this->chunk++; $this->pos = 0;
                if ($this->chunk >= count($this->chunks)) throw new RuntimeException('SST continuation mancante.');
                $flags = ord($this->chunks[$this->chunk][$this->pos]);
                $this->pos++;
                $wide = ($flags & 0x01) !== 0;
                continue;
            }
            $takeChars = min($remaining, $charsHere);
            $takeBytes = $takeChars * $bytesPerChar;
            $parts[] = [substr($this->chunks[$this->chunk], $this->pos, $takeBytes), $wide];
            $this->pos += $takeBytes;
            $remaining -= $takeChars;
            if ($remaining > 0 && $this->pos >= strlen($this->chunks[$this->chunk])) {
                $this->chunk++; $this->pos = 0;
                if ($this->chunk >= count($this->chunks)) throw new RuntimeException('SST continuation mancante.');
                $flags = ord($this->chunks[$this->chunk][$this->pos]);
                $this->pos++;
                $wide = ($flags & 0x01) !== 0;
            }
        }
        return $parts;
    }
}
