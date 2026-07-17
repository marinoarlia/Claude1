<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class OctopiaOrdersApi
{
    const API_BASE = 'https://api.octopia-io.net/seller/v2';
    const TOKEN_URL = 'https://auth.octopia-io.net/auth/realms/maas/protocol/openid-connect/token';

    private $sellerId;
    private $token = '';

    public function __construct()
    {
        $this->sellerId = trim((string) Configuration::get('CDO_SELLER_ID'));
    }

    public function isConfigured()
    {
        return trim((string) Configuration::get('CDO_CLIENT_ID')) !== ''
            && trim((string) Configuration::get('CDO_CLIENT_SECRET')) !== ''
            && $this->sellerId !== '';
    }

    public function getSellerId()
    {
        return $this->sellerId;
    }

    /**
     * Verifica le credenziali richiedendo un token OAuth a Octopia.
     * Usato dal pulsante "Verifica connessione" del pannello configurazione.
     */
    public function testConnection()
    {
        if (!$this->isConfigured()) {
            return $this->error('Inserisci Client ID, Client Secret e Seller ID prima di verificare la connessione.');
        }
        $this->token = '';
        $result = $this->getToken();
        if (!$result['success']) {
            return $result;
        }

        return ['success' => true, 'message' => 'token Octopia ottenuto correttamente.', 'body' => '', 'http_code' => 200, 'data' => null];
    }

    public function getOrders(array $query)
    {
        return $this->request('GET', '/orders?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public function getOrder($orderId)
    {
        return $this->request('GET', '/orders/'.rawurlencode((string) $orderId));
    }

    public function approveOrder($orderId)
    {
        return $this->request(
            'POST',
            '/orders/'.rawurlencode((string) $orderId).'/approval-status',
            ['approval_status' => 'Accepted']
        );
    }

    public function shipOrder($orderId, array $parcels)
    {
        return $this->request(
            'POST',
            '/orders/'.rawurlencode((string) $orderId).'/shipments',
            array_values($parcels)
        );
    }

    private function request($method, $path, $payload = null)
    {
        if (!$this->isConfigured()) {
            return $this->error('Credenziali Octopia mancanti nel modulo Cdiscount Sync.');
        }

        $tokenResult = $this->getToken();
        if (!$tokenResult['success']) {
            return $tokenResult;
        }

        $headers = [
            'Authorization: Bearer '.$this->token,
            'Accept: application/json',
            'SellerId: '.$this->sellerId,
        ];
        $body = null;
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($body === false) {
                return $this->error('Impossibile codificare la richiesta JSON Octopia.');
            }
        }

        $result = $this->httpRequest($method, self::API_BASE.$path, $headers, $body, 20);
        if (!$result['success'] && (int) $result['http_code'] === 401) {
            $this->token = '';
            $tokenResult = $this->getToken();
            if ($tokenResult['success']) {
                $headers[0] = 'Authorization: Bearer '.$this->token;
                $result = $this->httpRequest($method, self::API_BASE.$path, $headers, $body, 20);
            }
        }

        if (!$result['success']) {
            return $result;
        }

        $decoded = null;
        if (trim((string) $result['body']) !== '') {
            $decoded = json_decode($result['body'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->error('Risposta Octopia non JSON: '.Tools::substr($result['body'], 0, 500), $result['http_code'], $result['body']);
            }
        }

        $result['data'] = $decoded;

        return $result;
    }

    private function getToken()
    {
        if ($this->token !== '') {
            return ['success' => true, 'message' => ''];
        }

        $clientId = trim((string) Configuration::get('CDO_CLIENT_ID'));
        $clientSecret = trim((string) Configuration::get('CDO_CLIENT_SECRET'));
        if ($clientId === '' || $clientSecret === '') {
            return $this->error('Client ID o Client Secret Octopia mancanti.');
        }

        $body = http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials',
        ], '', '&', PHP_QUERY_RFC3986);

        $result = $this->httpRequest(
            'POST',
            self::TOKEN_URL,
            ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            $body,
            12
        );
        if (!$result['success']) {
            return $result;
        }

        $decoded = json_decode($result['body'], true);
        $this->token = isset($decoded['access_token']) ? trim((string) $decoded['access_token']) : '';
        if ($this->token === '') {
            return $this->error('Token Octopia non presente nella risposta.', $result['http_code'], $result['body']);
        }

        return ['success' => true, 'message' => ''];
    }

    private function httpRequest($method, $url, array $headers, $body = null, $timeout = 45)
    {
        $method = strtoupper((string) $method);
        if (!function_exists('curl_init')) {
            return $this->error('Estensione PHP cURL non disponibile.');
        }

        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            if (defined('CURLOPT_NOSIGNAL')) {
                curl_setopt($ch, CURLOPT_NOSIGNAL, true);
            }
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $responseBody = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($responseBody === false) {
                return $this->error('Errore cURL Octopia: '.$curlError, $httpCode);
            }
            if ($httpCode < 200 || $httpCode >= 300) {
                return $this->error('HTTP '.$httpCode.' - '.$this->extractApiError($responseBody), $httpCode, $responseBody);
            }

            return [
                'success' => true,
                'message' => '',
                'body' => (string) $responseBody,
                'http_code' => $httpCode,
                'data' => null,
            ];
        } catch (Exception $e) {
            return $this->error($e->getMessage());
        } catch (Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    private function extractApiError($body)
    {
        $decoded = json_decode((string) $body, true);
        if (is_array($decoded)) {
            $parts = [];
            foreach (['title', 'detail', 'message'] as $key) {
                if (!empty($decoded[$key])) {
                    $parts[] = (string) $decoded[$key];
                }
            }
            if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
                foreach ($decoded['errors'] as $field => $messages) {
                    $parts[] = $field.': '.implode(', ', (array) $messages);
                }
            }
            if ($parts) {
                return Tools::substr(implode(' | ', array_unique($parts)), 0, 1000);
            }
        }

        return Tools::substr(strip_tags((string) $body), 0, 1000);
    }

    private function error($message, $httpCode = 0, $body = '')
    {
        return [
            'success' => false,
            'message' => (string) $message,
            'body' => (string) $body,
            'http_code' => (int) $httpCode,
            'data' => null,
        ];
    }
}
