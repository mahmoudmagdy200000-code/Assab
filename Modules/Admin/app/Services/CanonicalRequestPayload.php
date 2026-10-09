<?php

namespace Modules\Admin\Services;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use JsonException;
use RuntimeException;

/** Builds a stable digest for an authenticated mutation's semantic input. */
class CanonicalRequestPayload
{
    public function hash(Request $request): string
    {
        $contentType = strtolower((string) $request->header('Content-Type'));
        $raw = $request->getContent();

        if (str_contains($contentType, 'application/json') || $this->looksLikeJson($raw)) {
            // Body-less JSON actions are valid (e.g. approve/settle).
            // Keep an explicit empty object as their canonical representation;
            // non-empty malformed JSON must still fail parsing.
            $body = $raw === '' ? '{}' : $this->canonicalJson($raw);
        } else {
            $body = $this->canonicalValue($request->request->all());
        }

        $files = $this->canonicalFiles($request->allFiles());

        return hash('sha256', json_encode([
            'body' => $body,
            'query' => $this->canonicalValue($request->query->all()),
            'files' => $files,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function looksLikeJson(string $raw): bool
    {
        $raw = ltrim($raw);

        return $raw !== '' && ($raw[0] === '{' || $raw[0] === '[');
    }

    /** Sort object keys while preserving JSON number lexemes exactly. */
    private function canonicalJson(string $raw): string
    {
        $offset = 0;
        $value = $this->parseValue($raw, $offset);
        $this->skipWhitespace($raw, $offset);

        if ($offset !== strlen($raw)) {
            throw new RuntimeException('Unexpected trailing JSON content.');
        }

        return $value;
    }

    private function parseValue(string $json, int &$offset): string
    {
        $this->skipWhitespace($json, $offset);
        $length = strlen($json);
        $char = $json[$offset] ?? '';

        if ($char === '{') {
            $offset++;
            $this->skipWhitespace($json, $offset);
            $members = [];

            if (($json[$offset] ?? '') === '}') {
                $offset++;

                return '{}';
            }

            while (true) {
                $this->skipWhitespace($json, $offset);
                $key = $this->parseString($json, $offset);
                $this->skipWhitespace($json, $offset);

                if (($json[$offset] ?? '') !== ':') {
                    throw new RuntimeException('Invalid JSON object.');
                }

                $offset++;

                $encodedKey = base64_encode($key);
                if (array_key_exists($encodedKey, $members)) {
                    throw new RuntimeException('Duplicate JSON object key.');
                }

                $members[$encodedKey] = [
                    'key' => $key,
                    'value' => $this->parseValue($json, $offset),
                ];
                $this->skipWhitespace($json, $offset);
                $separator = $json[$offset] ?? '';
                $offset++;

                if ($separator === '}') {
                    break;
                }

                if ($separator !== ',') {
                    throw new RuntimeException('Invalid JSON object separator.');
                }
            }

            usort($members, static fn (array $left, array $right): int => strcmp($left['key'], $right['key']));
            $encoded = [];
            foreach ($members as $member) {
                $encoded[] = json_encode($member['key'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).':'.$member['value'];
            }

            return '{'.implode(',', $encoded).'}';
        }

        if ($char === '[') {
            $offset++;
            $this->skipWhitespace($json, $offset);
            $items = [];

            if (($json[$offset] ?? '') === ']') {
                $offset++;

                return '[]';
            }

            while (true) {
                $items[] = $this->parseValue($json, $offset);
                $this->skipWhitespace($json, $offset);
                $separator = $json[$offset] ?? '';
                $offset++;

                if ($separator === ']') {
                    break;
                }

                if ($separator !== ',') {
                    throw new RuntimeException('Invalid JSON array separator.');
                }
            }

            return '['.implode(',', $items).']';
        }

        if ($char === '"') {
            return json_encode($this->decodeString($json, $offset), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        if (preg_match('/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $json, $match, 0, $offset) === 1) {
            $offset += strlen($match[0]);

            return $match[0];
        }

        foreach (['true', 'false', 'null'] as $literal) {
            if (substr($json, $offset, strlen($literal)) === $literal) {
                $offset += strlen($literal);

                return $literal;
            }
        }

        if ($offset >= $length) {
            throw new RuntimeException('Incomplete JSON payload.');
        }

        throw new RuntimeException('Invalid JSON payload.');
    }

    private function parseString(string $json, int &$offset): string
    {
        return $this->decodeString($json, $offset);
    }

    private function decodeString(string $json, int &$offset): string
    {
        $start = $offset;
        $length = strlen($json);
        $offset++;

        while ($offset < $length) {
            if ($json[$offset] === '\\') {
                $offset += 2;

                continue;
            }

            if ($json[$offset] === '"') {
                $offset++;
                $token = substr($json, $start, $offset - $start);

                try {
                    $decoded = json_decode($token, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new RuntimeException('Invalid JSON string.', previous: $exception);
                }

                if (! is_string($decoded)) {
                    throw new RuntimeException('Invalid JSON string.');
                }

                return $decoded;
            }

            $offset++;
        }

        throw new RuntimeException('Unterminated JSON string.');
    }

    private function skipWhitespace(string $json, int &$offset): void
    {
        $length = strlen($json);
        while ($offset < $length && str_contains(" \t\r\n", $json[$offset])) {
            $offset++;
        }
    }

    private function canonicalValue(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return null;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalValue($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalValue($item);
        }

        return $value;
    }

    private function canonicalFiles(array $files): array
    {
        $result = [];
        foreach ($files as $key => $file) {
            if (is_array($file)) {
                $result[$key] = $this->canonicalFiles($file);

                continue;
            }

            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            $result[$key] = [
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
            ];
        }

        if (! array_is_list($result)) {
            ksort($result, SORT_STRING);
        }

        return $result;
    }
}
