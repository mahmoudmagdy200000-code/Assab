<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\FileBag;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * PHP does not populate $_POST / $_FILES for multipart/form-data on PUT/PATCH.
 * This middleware manually parses the raw body and injects parsed fields and
 * uploaded files into the Request so FormRequest validation can see them.
 */
class ParseMultipartFormDataMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $method = strtoupper($request->getMethod());

        if (! in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $contentType = (string) $request->headers->get('Content-Type', '');

        if (stripos($contentType, 'multipart/form-data') === false) {
            return $next($request);
        }

        if (! preg_match('/boundary="?([^";]+)"?/i', $contentType, $matches)) {
            return $next($request);
        }

        $boundary = $matches[1];
        $rawBody = $request->getContent();

        if ($rawBody === '' || $rawBody === false) {
            return $next($request);
        }

        [$fields, $files] = $this->parseMultipart($rawBody, $boundary);

        if (! empty($fields)) {
            $flat = http_build_query($fields);
            parse_str($flat, $parsed);
            $request->request = new ParameterBag(array_merge($request->request->all(), $parsed));
            $request->merge($parsed);
        }

        if (! empty($files)) {
            $existing = $request->files->all();
            $request->files = new FileBag(array_merge($existing, $files));
        }

        return $next($request);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, UploadedFile|array>}
     */
    private function parseMultipart(string $body, string $boundary): array
    {
        $fields = [];
        $files = [];

        $delimiter = '--'.$boundary;
        $parts = explode($delimiter, $body);

        foreach ($parts as $part) {
            $part = ltrim($part, "\r\n");

            if ($part === '' || $part === '--' || str_starts_with($part, '--')) {
                continue;
            }

            $headerEnd = strpos($part, "\r\n\r\n");
            if ($headerEnd === false) {
                continue;
            }

            $rawHeaders = substr($part, 0, $headerEnd);
            $content = substr($part, $headerEnd + 4);
            $content = preg_replace('/\r\n$/', '', $content);

            $headers = $this->parseHeaders($rawHeaders);

            $disposition = $headers['content-disposition'] ?? null;
            if (! $disposition) {
                continue;
            }

            if (! preg_match('/name="([^"]+)"/', $disposition, $nameMatch)) {
                continue;
            }
            $name = $nameMatch[1];

            if (preg_match('/filename="([^"]*)"/', $disposition, $fileMatch)) {
                $filename = $fileMatch[1];

                if ($filename === '') {
                    continue;
                }

                $mime = $headers['content-type'] ?? 'application/octet-stream';
                $tmpPath = tempnam(sys_get_temp_dir(), 'lmp');
                file_put_contents($tmpPath, $content);

                $uploaded = new UploadedFile(
                    $tmpPath,
                    $filename,
                    $mime,
                    UPLOAD_ERR_OK,
                    true
                );

                $this->assignToBag($files, $name, $uploaded);
            } else {
                $this->assignToBag($fields, $name, $content);
            }
        }

        return [$fields, $files];
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (explode("\r\n", $raw) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
        return $headers;
    }

    private function assignToBag(array &$bag, string $name, mixed $value): void
    {
        if (str_ends_with($name, '[]')) {
            $key = substr($name, 0, -2);
            $bag[$key][] = $value;
            return;
        }

        if (str_contains($name, '[')) {
            $bag[$name] = $value;
            return;
        }

        $bag[$name] = $value;
    }
}
