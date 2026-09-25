<?php
namespace KornSW\KnowledgeRepo;

final class Http {
    public static function json(string $url, string $method = 'GET', ?array $body = null, string $token = '', bool $github = false): array {
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_PASS)) {
            throw new Failure('Remote-Endpunkte müssen HTTPS ohne Zugangsdaten in der URL verwenden.', 400);
        }
        $agent = 'KornSW-WordPress-KnowledgeRepo/0.1.5';
        $site = preg_replace('/[\r\n]/', '', home_url('/'));
        if ($site !== '') { $agent .= ' (+' . $site . ')'; }
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'User-Agent' => $agent];
        if ($token !== '') { $headers['Authorization'] = 'Bearer ' . $token; }
        if ($github) { $headers['Accept'] = 'application/vnd.github+json'; $headers['X-GitHub-Api-Version'] = '2022-11-28'; }
        $options = ['method' => $method, 'headers' => $headers, 'timeout' => 30, 'redirection' => 0, 'limit_response_size' => 33554432, 'user-agent' => $agent];
        if ($body !== null) { $options['body'] = wp_json_encode($body); }
        $response = wp_safe_remote_request($url, $options);
        if (is_wp_error($response)) { throw new Failure('Remote-Dienst nicht erreichbar.', 503); }
        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            $mapped = in_array($status, [409, 422], true) ? 409 : ($status === 404 ? 404 : 503);
            $reason = 'Remote-Dienst meldet HTTP ' . $status . '.';
            if ($github) {
                $reason = 'GitHub meldet HTTP ' . $status . '.';
                $error = json_decode(wp_remote_retrieve_body($response), true);
                $message = strtolower((string) ($error['message'] ?? ''));
                $remaining = (string) wp_remote_retrieve_header($response, 'x-ratelimit-remaining');
                if ($remaining === '0' || strpos($message, 'rate limit') !== false || $status === 429) {
                    $reason .= ' API-Limit erreicht. PAT hinterlegen oder nach Ablauf des Limits erneut laden.';
                    $reset = (string) wp_remote_retrieve_header($response, 'x-ratelimit-reset');
                    if (ctype_digit($reset)) { $reason .= ' Freigabe voraussichtlich ' . gmdate('H:i', (int) $reset) . ' UTC.'; }
                } elseif ($status === 403 || $status === 401) {
                    $reason .= ' PAT-Berechtigungen, Repository-Zugriff und gegebenenfalls Organisations-/SSO-Freigabe prüfen.';
                } elseif ($status === 404) { $reason .= ' Repository, Branch, Einstiegspfad oder Zugriffsrechte prüfen.'; }
            }
            // URLs are validated credential-free above; tokens travel only in the Authorization header.
            if ($status === 404) { $reason .= ' URL: ' . $method . ' ' . $url; }
            throw new Failure($reason, $mapped);
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) { throw new Failure('Ungültige Remote-Antwort.', 502); }
        return $data;
    }
}
final class RemoteRepository implements Repository {
    private $config; private $incoming; private $anonymousPassThrough;
    public function __construct(array $config, string $incoming = '', bool $anonymousPassThrough = false) { $this->config = $config; $this->incoming = $incoming; $this->anonymousPassThrough = $anonymousPassThrough; }
    public function call(string $method, array $args = []): array {
        $args = Contract::arguments($method, $args);
        if (Contract::mutation($method) && !empty($this->config['readonly'])) { return Contract::failure($method); }
        $token = $this->config['token'] ?? '';
        if ($token === '[PASS-TROUGH]') {
            if ($this->incoming === '' && !$this->anonymousPassThrough) { throw new Failure('Diese Quelle benötigt einen eingehenden UJMW-Token.', 403); }
            $token = $this->incoming;
        }
        $load = function () use ($method, $args, $token): array {
            $result = Http::json(rtrim($this->config['url'], '/') . '/' . rawurlencode($method), 'POST', $args, $token);
            if (!empty($result['fault'])) { throw new Failure('Der externe Wissensdienst meldet einen Fehler.', 502); }
            if ($method !== 'GetAreaCapabilities' && !array_key_exists('return', $result)) { throw new Failure('Unvollständige UJMW-Antwort.', 502); }
            return $result;
        };
        return $load();
    }
}
