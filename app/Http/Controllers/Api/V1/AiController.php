<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClassifyProductRequest;
use App\Http\Requests\GenerateDescriptionRequest;
use App\Http\Resources\AiLogResource;
use App\Http\Resources\CategoryResource;
use App\Models\AiLog;
use App\Services\AiIntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class AiController extends Controller
{
    public function __construct(private readonly AiIntegrationService $aiService) {}

    public function classify(ClassifyProductRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = $this->aiService->classify(
                namaBarang: $validated['nama_barang'],
                productId: isset($validated['product_id']) ? (int) $validated['product_id'] : null,
            );
        } catch (RuntimeException $e) {
            $status = in_array($e->getCode(), [502, 503], true) ? $e->getCode() : 503;

            return response()->json([
                'message' => $e->getMessage(),
            ], $status);
        }

        return response()->json([
            'message' => 'Klasifikasi kategori berhasil.',
            'data' => [
                'predicted_category' => $result['predicted_category'],
                'matched_category' => $result['matched_category'] ? new CategoryResource($result['matched_category']) : null,
                'confidence_score' => $result['confidence_score'],
                'model_name' => $result['model_name'],
                'ai_log' => new AiLogResource($result['ai_log']),
            ],
        ]);
    }

    public function generateDescription(GenerateDescriptionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = $this->aiService->generateDescription(
                payload: $validated,
                productId: isset($validated['product_id']) ? (int) $validated['product_id'] : null,
                applyToProduct: (bool) ($validated['apply_to_product'] ?? false),
            );
        } catch (RuntimeException $e) {
            $status = in_array($e->getCode(), [502, 503], true) ? $e->getCode() : 503;

            return response()->json([
                'message' => $e->getMessage(),
            ], $status);
        }

        return response()->json([
            'message' => 'Deskripsi produk berhasil dihasilkan.',
            'data' => [
                'deskripsi' => $result['deskripsi'],
                'model_name' => $result['model_name'],
                'applied_to_product' => $result['applied_to_product'],
                'ai_log' => new AiLogResource($result['ai_log']),
            ],
        ]);
    }

    public function logs(Request $request): AnonymousResourceCollection
    {
        $query = AiLog::query()->with('product');

        if ($productId = $request->query('product_id')) {
            $query->where('product_id', (int) $productId);
        }

        if ($aiType = strtoupper(trim((string) $request->query('ai_type', '')))) {
            if (in_array($aiType, ['CLASSIFICATION', 'GEN_AI_DESCRIPTION'], true)) {
                $query->where('ai_type', $aiType);
            }
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return AiLogResource::collection(
            $query->latest()->paginate($perPage)->withQueryString()
        );
    }
}
