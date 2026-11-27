<?php

namespace App\Services;

use App\Models\AiLog;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiIntegrationService
{
    /**
     * Request product category classification from external FastAPI AI Service.
     *
     * @return array{
     *     predicted_category: string,
     *     matched_category: Category|null,
     *     confidence_score: float|null,
     *     model_name: string|null,
     *     ai_log: AiLog
     * }
     */
    public function classify(string $namaBarang, ?int $productId = null): array
    {
        $baseUrl = (string) config('services.ai_service.url');
        $timeout = (int) config('services.ai_service.timeout', 15);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->post("{$baseUrl}/classify", [
                    'nama_barang' => $namaBarang,
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Layanan AI (FastAPI) tidak dapat dihubungi saat ini.', 503, $e);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Layanan AI mengembalikan error (HTTP '.$response->status().').',
                502
            );
        }

        $data = $response->json() ?? [];
        $predictedCategory = (string) (
            $data['predicted_category']
            ?? $data['category']
            ?? $data['kategori']
            ?? $data['label']
            ?? ''
        );

        if ($predictedCategory === '') {
            throw new RuntimeException('Respons layanan AI tidak berisi hasil prediksi kategori.', 502);
        }

        $confidenceRaw = $data['confidence_score'] ?? $data['confidence'] ?? null;
        $confidenceScore = $confidenceRaw !== null ? round((float) $confidenceRaw, 4) : null;
        $modelName = isset($data['model_name']) || isset($data['model'])
            ? (string) ($data['model_name'] ?? $data['model'])
            : null;

        $matchedCategory = Category::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($predictedCategory))])
            ->orWhereRaw('LOWER(code) = ?', [mb_strtolower(trim($predictedCategory))])
            ->first();

        $aiLog = AiLog::query()->create([
            'product_id' => $productId,
            'ai_type' => 'CLASSIFICATION',
            'input_text' => $namaBarang,
            'output_result' => $predictedCategory,
            'confidence_score' => $confidenceScore,
            'model_name' => $modelName,
        ]);

        return [
            'predicted_category' => $predictedCategory,
            'matched_category' => $matchedCategory,
            'confidence_score' => $confidenceScore,
            'model_name' => $modelName,
            'ai_log' => $aiLog->load('product'),
        ];
    }

    /**
     * Request Generative AI product description from external FastAPI AI Service.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     deskripsi: string,
     *     model_name: string|null,
     *     applied_to_product: bool,
     *     ai_log: AiLog
     * }
     */
    public function generateDescription(
        array $payload,
        ?int $productId = null,
        bool $applyToProduct = false,
    ): array {
        $baseUrl = (string) config('services.ai_service.url');
        $timeout = (int) config('services.ai_service.timeout', 15);

        $requestBody = array_filter([
            'nama_barang' => $payload['nama_barang'] ?? null,
            'kategori' => $payload['kategori'] ?? null,
            'harga' => $payload['harga'] ?? null,
            'satuan' => $payload['satuan'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->post("{$baseUrl}/generate-description", $requestBody);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Layanan AI (FastAPI) tidak dapat dihubungi saat ini.', 503, $e);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Layanan AI mengembalikan error (HTTP '.$response->status().').',
                502
            );
        }

        $data = $response->json() ?? [];
        $description = trim((string) (
            $data['deskripsi']
            ?? $data['description']
            ?? $data['output_result']
            ?? ''
        ));

        if ($description === '') {
            throw new RuntimeException('Respons layanan AI tidak berisi teks deskripsi.', 502);
        }

        $modelName = isset($data['model_name']) || isset($data['model'])
            ? (string) ($data['model_name'] ?? $data['model'])
            : null;

        $aiLog = AiLog::query()->create([
            'product_id' => $productId,
            'ai_type' => 'GEN_AI_DESCRIPTION',
            'input_text' => (string) ($payload['nama_barang'] ?? ''),
            'output_result' => $description,
            'confidence_score' => null,
            'model_name' => $modelName,
        ]);

        $applied = false;
        if ($applyToProduct && $productId !== null) {
            Product::query()->whereKey($productId)->update([
                'deskripsi' => $description,
            ]);
            $applied = true;
        }

        return [
            'deskripsi' => $description,
            'model_name' => $modelName,
            'applied_to_product' => $applied,
            'ai_log' => $aiLog->load('product'),
        ];
    }
}
