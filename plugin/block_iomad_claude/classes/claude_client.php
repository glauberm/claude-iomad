<?php
namespace block_iomad_claude;

defined('MOODLE_INTERNAL') || die();

class claude_client {

    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const MAX_TOOL_ROUNDS = 5;

    private string $apikey;
    private string $model;
    private iomad_tools $tools;

    public function __construct(string $apikey, string $model, iomad_tools $tools) {
        $this->apikey = $apikey;
        $this->model  = $model;
        $this->tools  = $tools;
    }

    public function answer(string $question, int $companyid): string {
        $system = "You are an IOMAD school analytics assistant. "
            . "Use the provided tools to look up real data and answer questions about the school. "
            . "Be concise and factual. Format numbers clearly. "
            . "The user's school company ID is {$companyid} — all tools are already scoped to it, "
            . "so do not pass a companyid in tool inputs.";

        $messages = [
            ['role' => 'user', 'content' => $question],
        ];

        $tool_defs = iomad_tools::tool_definitions();

        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $payload = [
                'model'      => $this->model,
                'max_tokens' => 1024,
                'system'     => $system,
                'tools'      => $tool_defs,
                'messages'   => $messages,
            ];

            $response = $this->post($payload);

            if ($response['stop_reason'] === 'end_turn') {
                foreach ($response['content'] as $block) {
                    if ($block['type'] === 'text') {
                        return $block['text'];
                    }
                }
                return '';
            }

            if ($response['stop_reason'] === 'tool_use') {
                $tool_results = [];

                foreach ($response['content'] as $block) {
                    if ($block['type'] !== 'tool_use') {
                        continue;
                    }

                    $result = $this->tools->dispatch($block['name'], $block['input'] ?? []);

                    $tool_results[] = [
                        'type'        => 'tool_result',
                        'tool_use_id' => $block['id'],
                        'content'     => json_encode($result),
                    ];
                }

                // json_decode(true) turns {} into [] — re-encode tool_use inputs as objects.
                $assistant_content = $response['content'];
                foreach ($assistant_content as &$b) {
                    if ($b['type'] === 'tool_use' && is_array($b['input']) && empty($b['input'])) {
                        $b['input'] = new \stdClass();
                    }
                }
                unset($b);

                $messages[] = ['role' => 'assistant', 'content' => $assistant_content];
                $messages[] = ['role' => 'user',      'content' => $tool_results];

                continue;
            }

            // Unexpected stop reason
            throw new \moodle_exception('error', 'block_iomad_claude', '', null,
                'Unexpected stop_reason: ' . ($response['stop_reason'] ?? 'null'));
        }

        throw new \moodle_exception('error', 'block_iomad_claude', '', null,
            'No answer returned after ' . self::MAX_TOOL_ROUNDS . ' tool rounds');
    }

    private function post(array $payload): array {
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apikey,
                'anthropic-version: ' . self::API_VERSION,
            ],
            CURLOPT_TIMEOUT        => 60,
        ]);

        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new \moodle_exception('error', 'block_iomad_claude', '', null,
                "cURL error $errno: $error");
        }

        $data = json_decode($body, true);

        if ($http !== 200) {
            $msg = $data['error']['message'] ?? $body;
            throw new \moodle_exception('error', 'block_iomad_claude', '', null,
                "Anthropic API error $http: $msg");
        }

        return $data;
    }
}
