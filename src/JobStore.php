<?php
declare(strict_types=1);

final class JobStore
{
    private string $dir;

    public function __construct(string $dataDir)
    {
        $this->dir = rtrim($dataDir, '/') . '/jobs';
        if (!is_dir($this->dir) && !mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new RuntimeException('Impossibile creare la cartella dati.');
        }
    }

    public function create(array $rows): string
    {
        $id = bin2hex(random_bytes(16));
        $job = [
            'id' => $id,
            'created_at' => date(DATE_ATOM),
            'rows' => array_values($rows),
        ];
        $this->write($id, $job);
        return $id;
    }

    public function load(string $id): array
    {
        $this->assertId($id);
        $path = $this->dir . '/' . $id . '.json';
        if (!is_file($path)) {
            throw new RuntimeException('Lavorazione non trovata.');
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data) || !isset($data['rows'])) {
            throw new RuntimeException('File lavorazione non valido.');
        }
        return $data;
    }

    public function write(string $id, array $job): void
    {
        $this->assertId($id);
        $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($this->dir . '/' . $id . '.json', $json, LOCK_EX) === false) {
            throw new RuntimeException('Impossibile salvare la lavorazione.');
        }
    }

    private function assertId(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new RuntimeException('ID lavorazione non valido.');
        }
    }
}

