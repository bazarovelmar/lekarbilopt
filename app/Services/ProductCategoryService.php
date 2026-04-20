<?php

namespace App\Services;

class ProductCategoryService
{
    protected ?array $catalog = null;

    public function buildSelectionPrompt(): string
    {
        $categories = $this->loadCatalog();
        if (empty($categories)) {
            return 'Категории недоступны.';
        }

        $lines = [];
        foreach ($categories as $category) {
            if (!is_array($category)) {
                continue;
            }
            $slug = (string) ($category['slug'] ?? '');
            $name = (string) ($category['name'] ?? '');
            if ($slug === '' || $name === '') {
                continue;
            }

            $lines[] = "- {$slug}: {$name}";
            $children = $category['children'] ?? [];
            if (!is_array($children)) {
                continue;
            }
            foreach ($children as $child) {
                if (!is_array($child)) {
                    continue;
                }
                $childSlug = (string) ($child['slug'] ?? '');
                $childName = (string) ($child['name'] ?? '');
                if ($childSlug === '' || $childName === '') {
                    continue;
                }
                $lines[] = "  - {$childSlug}: {$childName}";
            }
        }

        return implode("\n", $lines);
    }

    public function normalizeSelection(array $data): array
    {
        $catalog = $this->loadCatalog();
        $categorySlug = trim((string) ($data['category_slug'] ?? ''));
        $subcategorySlug = trim((string) ($data['subcategory_slug'] ?? ''));

        $selectedCategory = $this->findCategoryBySlug($catalog, $categorySlug);
        if (!$selectedCategory) {
            $selectedCategory = $this->findCategoryBySlug($catalog, 'other');
            $categorySlug = $selectedCategory ? 'other' : $categorySlug;
        }

        $selectedSubcategory = null;
        if ($selectedCategory) {
            $children = $selectedCategory['children'] ?? [];
            if (is_array($children) && $subcategorySlug !== '') {
                foreach ($children as $index => $child) {
                    if (!is_array($child)) {
                        continue;
                    }
                    if ((string) ($child['slug'] ?? '') === $subcategorySlug) {
                        $selectedSubcategory = [
                            'id' => ((int) ($selectedCategory['id'] ?? 0) * 1000) + ((int) $index + 1),
                            'slug' => (string) $child['slug'],
                            'name' => (string) ($child['name'] ?? ''),
                        ];
                        break;
                    }
                }
            }
        }

        if (!$selectedCategory) {
            return $data;
        }

        $data['ai_category'] = [
            'id' => (int) ($selectedCategory['id'] ?? 0),
            'slug' => (string) ($selectedCategory['slug'] ?? ''),
            'name' => (string) ($selectedCategory['name'] ?? ''),
        ];
        $data['ai_subcategory'] = $selectedSubcategory;
        $data['category_slug'] = (string) ($data['ai_category']['slug'] ?? $categorySlug);
        $data['subcategory_slug'] = (string) ($selectedSubcategory['slug'] ?? '');

        return $data;
    }

    public function applyToItems(array $items, array $analysis): array
    {
        $category = is_array($analysis['ai_category'] ?? null) ? $analysis['ai_category'] : null;
        $subcategory = is_array($analysis['ai_subcategory'] ?? null) ? $analysis['ai_subcategory'] : null;
        if (!$category) {
            return $items;
        }

        foreach ($items as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $item['ai_category'] = $category;
            $item['ai_subcategory'] = $subcategory;
        }
        unset($item);

        return $items;
    }

    protected function loadCatalog(): array
    {
        if (is_array($this->catalog)) {
            return $this->catalog;
        }

        $path = (string) config('bot.product_categories_config', base_path('config/product_categories.json'));
        if ($path === '' || !is_file($path)) {
            return $this->catalog = [];
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return $this->catalog = [];
        }

        $decoded = json_decode($raw, true);
        $categories = is_array($decoded) ? ($decoded['categories'] ?? []) : [];

        return $this->catalog = (is_array($categories) ? $categories : []);
    }

    protected function findCategoryBySlug(array $catalog, string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }
        foreach ($catalog as $category) {
            if (!is_array($category)) {
                continue;
            }
            if ((string) ($category['slug'] ?? '') === $slug) {
                return $category;
            }
        }

        return null;
    }
}
