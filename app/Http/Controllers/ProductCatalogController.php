<?php

namespace App\Http\Controllers;

use App\Models\WbCategory;
use App\Models\WbProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ProductCatalogController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $categoryId = $request->query('category');
        $subcategoryId = $request->query('subcategory');
        $sort = $request->query('sort', 'newest');

        $query = WbProduct::query();

        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(title) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(brand) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(supplier) LIKE ?', [$like]);
            });
        }

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        if ($subcategoryId) {
            $query->where('subcategory_id', $subcategoryId);
        }

        match ($sort) {
            'title' => $query->orderBy('title'),
            'brand' => $query->orderBy('brand'),
            default => $query->orderByDesc('id'),
        };

        $products = $query->paginate(24)->withQueryString();

        $parentCategories = WbCategory::query()
            ->whereNull('parent_wb_subject_id')
            ->orderBy('name')
            ->get();

        $subcategories = collect();
        if ($categoryId) {
            $parent = WbCategory::find($categoryId);
            if ($parent) {
                $subcategories = WbCategory::query()
                    ->where('parent_wb_subject_id', $parent->wb_subject_id)
                    ->orderBy('name')
                    ->get();
            }
        }

        $totalCount = WbProduct::count();

        return view('catalog.index', [
            'products' => $products,
            'parentCategories' => $parentCategories,
            'subcategories' => $subcategories,
            'search' => $search,
            'categoryId' => $categoryId ? (int) $categoryId : null,
            'subcategoryId' => $subcategoryId ? (int) $subcategoryId : null,
            'sort' => $sort,
            'totalCount' => $totalCount,
        ]);
    }

    public function show(int $id)
    {
        $product = WbProduct::findOrFail($id);

        $category = $product->category_id ? WbCategory::find($product->category_id) : null;
        $subcategory = $product->subcategory_id ? WbCategory::find($product->subcategory_id) : null;

        return view('catalog.show', [
            'product' => $product,
            'category' => $category,
            'subcategory' => $subcategory,
        ]);
    }

    public function image(int $id): Response|BinaryFileResponse
    {
        $product = WbProduct::findOrFail($id);
        $path = $product->image_path;

        if (! $path) {
            return $this->placeholder();
        }

        $absolute = null;
        if (is_file($path)) {
            $absolute = $path;
        } else {
            $disk = Storage::disk('local');
            if ($disk->exists($path)) {
                $absolute = $disk->path($path);
            }
        }

        if (! $absolute || ! is_file($absolute)) {
            return $this->placeholder();
        }

        return response()->file($absolute, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    protected function placeholder(): Response
    {
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400">
    <rect width="400" height="400" fill="#f0f9ff"/>
    <g fill="#0369a1" opacity="0.4">
        <circle cx="200" cy="170" r="60"/>
        <rect x="120" y="240" width="160" height="20" rx="10"/>
        <rect x="150" y="275" width="100" height="14" rx="7"/>
    </g>
</svg>
SVG;

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
