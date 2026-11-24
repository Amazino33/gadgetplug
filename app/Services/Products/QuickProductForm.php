<?php

namespace App\Services\Products;

use App\Filament\Vendor\Resources\Products\ProductResource;
use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use App\Models\Category;
use App\Models\FreePlanSetting;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tag;
use App\Models\Vendor;
use App\Services\ActiveStore;
use App\Services\FreePlan\FreePlanLimits;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The product pop-up on the Products list: everything the Filament product
 * form does, but filled in the browser and sent to the server once, on Save.
 *
 * The Filament form asks the server something on nearly every field — the
 * margin preview, the SKU preview, each "Add barcode" row — and on a shop's
 * mobile data every one of those is a wait. Here the browser holds the whole
 * form and this class does the one thing that has to happen on the server:
 * check it the way the Filament form would, and save it.
 *
 * The Filament create and edit pages are left in place. They are still where
 * "Enhance with AI", archiving and deleting live, and a link from the pop-up
 * opens them.
 */
class QuickProductForm
{
    /** Who sees what, and the lists the form chooses from — sent with the page. */
    public function options(Vendor $vendor): array
    {
        $style = app(SkuGenerator::class)->styleFor($vendor->id);
        $canChooseStore = auth()->user()?->can('create', Store::class) ?? false;
        $stores = $canChooseStore
            ? Store::where('vendor_id', $vendor->id)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all()
            : [];

        return [
            'categories'     => Category::orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all(),
            'stores'         => $stores,
            'suppliers'      => Supplier::where('vendor_id', $vendor->id)->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all(),
            'tags'           => Tag::where('vendor_id', $vendor->id)->orderBy('name')->get(['id', 'name'])->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->all(),
            'commonUnits'    => ProductForm::COMMON_UNITS,
            // The branch being worked in; with none chosen, the shop's main one.
            'defaultStoreId' => ActiveStore::currentId() ?? ($stores[0]['id'] ?? null),
            'canChooseStore' => $canChooseStore,
            'canSeeCost'     => ProductForm::canSeeCostPrice(),
            'canCreate'      => ProductResource::canCreate(),
            'halves'         => ProductForm::usesHalfQuantities(),
            'gadget'         => ProductForm::usesGadgetFields(),
            // GadgetPlug has no shop "Sold out" switch, and every new product
            // starts as a draft, whatever the plan (as ProductForm does here).
            'freePlan'       => false,
            'defaultStatus'  => 'draft',
            'skuManual'      => $style === SkuGenerator::STYLE_MANUAL,
            'skuHint'        => match ($style) {
                SkuGenerator::STYLE_SERIAL        => 'Next number in your sequence',
                SkuGenerator::STYLE_PREFIX_SERIAL => 'Your prefix + next number',
                SkuGenerator::STYLE_MANUAL        => 'You type this one',
                default                           => 'Generated from category + name',
            },
            'maxPhotos'      => $this->maxPhotos($vendor),
            'limitReached'   => FreePlanLimits::productLimitReached($vendor),
            'limitMessage'   => FreePlanLimits::productLimitMessage(),
        ];
    }

    /** A saved product in the shape the pop-up edits. */
    public function load(Product $product): array
    {
        // Not extraBaseBarcodes: eager-loaded, that relation is built on a blank
        // product and so cannot leave out this one's main barcode.
        $product->loadMissing(['extraUnits.barcodes', 'tags', 'media']);

        $default = $product->units()->find($product->default_unit_id);
        $money = fn ($v) => $v === null ? '' : (string) (0 + $v);
        $int = fn ($v) => $v === null ? '' : (string) $v;

        return [
            'id'                      => $product->id,
            'linked'                  => $product->isLinked(),
            'current_status'          => $product->status,
            'edit_url'                => ProductResource::getUrl('edit', ['record' => $product]),
            'name'                    => $product->name,
            'sku'                     => (string) $product->sku,
            'sku_manual'              => false,
            'category_id'             => $product->category_id ? (string) $product->category_id : '',
            'store_id'                => $product->store_id ? (string) $product->store_id : '',
            'cost_price'              => ProductForm::canSeeCostPrice() ? $money($product->cost_price) : '',
            // The accessor: a linked listing shows the supplier's live price,
            // which is what the locked field on the Filament form shows too.
            'price'                   => $money($product->price),
            'status'                  => $product->status === 'archived' ? 'archived' : $product->status,
            'base_unit'               => $product->base_unit ?: 'Piece',
            'wholesale_price'         => $money($product->wholesale_price),
            'half_price'              => $money($product->half_price),
            'default_unit_choice'     => ($default && ! $default->is_base) ? 'unit:'.$default->name : 'base',
            'extraUnits'              => $product->extraUnits->map(fn (ProductUnit $u) => [
                'id'                => $u->id,
                'name'              => $u->name,
                'sku'               => (string) $u->sku,
                'conversion_factor' => (string) $u->conversion_factor,
                'price'             => $money($u->price),
                'wholesale_price'   => $money($u->wholesale_price),
                'half_price'        => $money($u->half_price),
                'is_active'         => (bool) $u->is_active,
                'barcodes'          => $u->barcodes->pluck('barcode')->values()->all(),
            ])->values()->all(),
            'barcode'                 => (string) $product->barcode,
            'extraBaseBarcodes'       => $product->extraBaseBarcodes()->orderBy('id')->pluck('barcode')->values()->all(),
            'photos'                  => $product->isLinked() ? [] : $product->getMedia('product-images')->map(fn (Media $m) => [
                'uuid' => $m->uuid,
                'url'  => $m->hasGeneratedConversion('thumb') ? $m->getUrl('thumb') : $m->getUrl(),
            ])->values()->all(),
            'brand'                   => (string) $product->brand,
            'search_keywords'         => (string) $product->search_keywords,
            // Words learned from staff searches; any removed here are sent
            // back in forget_words.
            'learned_words'           => app(SearchShortcuts::class)->allFor($product),
            'forget_words'            => [],
            'supplier_id'             => $product->supplier_id ? (string) $product->supplier_id : '',
            'new_supplier'            => null,
            'description'             => (string) $product->description,
            'tags'                    => $product->tags->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
            'new_tags'                => [],
            'specifications'          => collect($product->specifications ?? [])->map(fn ($v, $k) => ['key' => (string) $k, 'value' => (string) $v])->values()->all(),
            'low_stock_threshold'     => $int($product->low_stock_threshold ?? 5),
            'reorder_point'           => $int($product->reorder_point),
            'preferred_quantity'      => $int($product->preferred_quantity),
            'negative_stock_override' => $product->negative_stock_override === null ? '' : ($product->negative_stock_override ? '1' : '0'),
            'is_service'              => (bool) $product->is_service,
            'track_serial'            => (bool) $product->track_serial,
            'has_custom_price'        => (bool) $product->has_custom_price,
            'allow_pos_price_override' => (bool) ($product->allow_pos_price_override ?? true),
            'show_in_pos'             => (bool) ($product->show_in_pos ?? true),
            'show_online'             => (bool) ($product->show_online ?? true),
            'published_at'            => $this->toLocal($product->published_at),
            'unpublish_at'            => $this->toLocal($product->unpublish_at),
        ];
    }

    /**
     * Checks the form the way ProductForm does, then saves it in one go.
     *
     * @param  array<int, TemporaryUploadedFile>  $uploads  new photos, in the order the form lists them
     *
     * @throws ValidationException with keys that match the pop-up's fields
     */
    public function save(Vendor $vendor, ?Product $product, array $in, array $uploads = []): Product
    {
        $in = $this->normalise($in);
        $canSeeCost = ProductForm::canSeeCostPrice();
        $canChooseStore = auth()->user()?->can('create', Store::class) ?? false;
        $skuManualShop = ProductForm::skuIsManual();
        $linkedLocked = $product?->isLinked() && ! ($in['has_custom_price'] ?? false);
        $unitIds = $product ? $product->extraUnits()->pluck('id')->all() : [];

        $rules = [
            'name'                         => ['required', 'string', 'max:255'],
            'sku'                          => ['nullable', 'string', 'max:100'],
            'category_id'                  => ['required', 'integer', Rule::exists('categories', 'id')],
            'status'                       => ['nullable', Rule::in(['draft', 'published', 'archived'])],
            'base_unit'                    => ['required', 'string', 'max:32'],
            'wholesale_price'              => ['nullable', 'numeric', 'min:0'],
            'half_price'                   => ['nullable', 'numeric', 'min:0'],
            'barcode'                      => ['nullable', 'string', 'max:100'],
            'extraBaseBarcodes'            => ['array'],
            'extraBaseBarcodes.*'          => ['required', 'string', 'max:100'],
            'extraUnits'                   => ['array'],
            'extraUnits.*.id'              => ['nullable', 'integer', Rule::in($unitIds)],
            'extraUnits.*.name'            => ['required', 'string', 'max:64', 'distinct:ignore_case'],
            'extraUnits.*.conversion_factor' => ['required', 'integer', 'min:1'],
            'extraUnits.*.price'           => ['required', 'numeric', 'min:0'],
            'extraUnits.*.wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'extraUnits.*.half_price'      => ['nullable', 'numeric', 'min:0'],
            'extraUnits.*.barcodes'        => ['array'],
            'extraUnits.*.barcodes.*'      => ['required', 'string', 'max:100'],
            'brand'                        => ['nullable', 'string', 'max:255'],
            'search_keywords'              => ['nullable', 'string', 'max:500'],
            'forget_words'                 => ['array'],
            'forget_words.*'               => ['string', 'max:60'],
            'supplier_id'                  => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('vendor_id', $vendor->id)],
            'new_supplier.name'            => ['nullable', 'string', 'max:255'],
            'new_supplier.phone'           => ['nullable', 'string', 'max:30'],
            'description'                  => ['nullable', 'string'],
            'tags'                         => ['array'],
            'tags.*'                       => ['integer', Rule::exists('tags', 'id')->where('vendor_id', $vendor->id)],
            'new_tags'                     => ['array'],
            'new_tags.*'                   => ['string', 'max:100'],
            'specifications'               => ['array'],
            'specifications.*.key'         => ['nullable', 'string', 'max:100'],
            'specifications.*.value'       => ['nullable', 'string', 'max:255'],
            'low_stock_threshold'          => ['required', 'numeric', 'min:0'],
            'reorder_point'                => ['nullable', 'numeric', 'min:0'],
            'preferred_quantity'           => ['nullable', 'numeric', 'min:0'],
            'negative_stock_override'      => ['nullable', Rule::in(['0', '1'])],
            'published_at'                 => ['nullable', 'date'],
            'unpublish_at'                 => ['nullable', 'date'],
            'photos'                       => ['array'],
        ];

        // A shop that types its own SKUs must give one; a SKU someone typed or
        // a saved product's SKU must not clash. A generated preview on a new
        // product is thrown away on save, so it is not checked.
        $skuTyped = $skuManualShop || ($in['sku_manual'] ?? false);
        if ($skuManualShop) {
            $rules['sku'][] = 'required';
        }
        if ($product || $skuTyped) {
            $rules['sku'][] = Rule::unique('products', 'sku')->where('vendor_id', $vendor->id)->ignore($product?->getKey());
        }

        if ($canChooseStore) {
            $rules['store_id'] = ['required', 'integer', Rule::exists('stores', 'id')->where('vendor_id', $vendor->id)];
        }

        if ($canSeeCost) {
            $rules['cost_price'] = ['nullable', 'numeric', 'min:0'];
        }

        if (! $linkedLocked) {
            $rules['price'] = ['required', 'numeric', 'min:0'];
            if ($canSeeCost && filled($in['cost_price'] ?? null)) {
                $rules['price'][] = 'gt:cost_price';
            }
        }

        if (filled($in['published_at'] ?? null) && filled($in['unpublish_at'] ?? null)) {
            $rules['unpublish_at'][] = 'after:published_at';
        }

        $validator = Validator::make($in, $rules, [
            'price.gt'                      => 'The selling price must be more than the cost price.',
            'sku.unique'                    => 'This SKU is already used by another product in this shop.',
            'extraUnits.*.name.distinct'    => 'Two units have the same name.',
            'extraUnits.*.id.in'            => 'This unit no longer belongs to the product. Reload and try again.',
            'unpublish_at.after'            => 'The unpublish date must be after the publish date.',
        ], [
            'category_id'                    => 'category',
            'store_id'                       => 'home store',
            'base_unit'                      => 'base unit',
            'extraBaseBarcodes.*'            => 'alternative barcode',
            'extraUnits.*.name'              => 'unit name',
            'extraUnits.*.conversion_factor' => '"contains"',
            'extraUnits.*.price'             => 'retail price',
            'extraUnits.*.wholesale_price'   => 'wholesale price',
            'extraUnits.*.barcodes.*'        => 'barcode',
            'low_stock_threshold'            => 'low stock alert',
        ]);

        $validator->after(function ($v) use ($in, $vendor, $product) {
            $this->checkBarcodes($v, $in, $vendor->id, $product?->id);
            $this->checkPhotos($v, $in, $product, $vendor);
        });

        $validator->validate();

        foreach ($uploads as $file) {
            if (! $file instanceof TemporaryUploadedFile || ! str_starts_with((string) $file->getMimeType(), 'image/')) {
                throw ValidationException::withMessages(['photos' => 'Only photos can be added here.']);
            }
        }

        return DB::transaction(function () use ($vendor, $product, $in, $uploads, $canSeeCost, $canChooseStore, $skuTyped, $linkedLocked) {
            $creating = $product === null;
            $product ??= new Product(['vendor_id' => $vendor->id]);

            // Removed barcodes go first, so a code moved from one unit to
            // another (or to the main barcode) is free by the time it is added.
            if (! $creating) {
                $this->deleteRemovedBarcodesAndUnits($product, $in);
            }

            $product->fill($this->productAttributes($in, $product, $creating, $canSeeCost, $linkedLocked));

            if ($creating) {
                $product->vendor_id = $vendor->id;
                $product->store_id = $canChooseStore ? (int) $in['store_id'] : ActiveStore::currentId();
                // The SKU on screen while typing is only a preview: the real one
                // is generated on save, so two products created at once never
                // share a preview (see CreateProduct).
                $product->sku = $skuTyped ? $in['sku'] : null;
            } else {
                if ($canChooseStore && filled($in['store_id'] ?? null)) {
                    $product->store_id = (int) $in['store_id'];
                }
                if (filled($in['sku'] ?? null)) {
                    $product->sku = $in['sku'];
                }
            }

            if (filled($in['new_supplier']['name'] ?? null)) {
                $product->supplier_id = Supplier::create([
                    'vendor_id' => $vendor->id,
                    'name'      => trim($in['new_supplier']['name']),
                    'phone'     => $in['new_supplier']['phone'] ?? null,
                ])->getKey();
            }

            $product->save();

            $this->saveUnitsAndBarcodes($product, $in);

            $tagIds = collect($in['tags'] ?? [])->map(fn ($id) => (int) $id);
            foreach ($in['new_tags'] ?? [] as $name) {
                if (filled(trim($name))) {
                    $tagIds->push(Tag::firstOrCreate(['vendor_id' => $vendor->id, 'name' => trim($name)])->getKey());
                }
            }
            $product->tags()->sync($tagIds->unique()->values()->all());

            // A resold listing's photos come from the supplier's product and
            // follow it; the pop-up never touches them.
            if (! $product->isLinked()) {
                $this->savePhotos($product, $in['photos'] ?? [], $uploads);
            }

            if (! $creating) {
                app(SearchShortcuts::class)->forget($product, $in['forget_words'] ?? []);
            }

            static::applyDefaultUnit($product, $in['default_unit_choice'] ?? null);

            return $product->fresh();
        });
    }

    /** A SKU this product would get, for the preview beside the name. */
    public function previewSku(Vendor $vendor, ?string $name, $categoryId, ?int $productId = null): ?string
    {
        if (blank($name)) {
            return null;
        }

        return app(SkuGenerator::class)->for($vendor->id, filled($categoryId) ? (int) $categoryId : null, (string) $name, $productId);
    }

    /** Why a barcode cannot be used, or null when it is free. */
    public function barcodeProblem(Vendor $vendor, string $code, ?int $productId = null): ?string
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        $owner = ProductBarcode::where('vendor_id', $vendor->id)->where('barcode', $code)
            ->when($productId, fn ($q) => $q->where('product_id', '!=', $productId))
            ->value('product_id')
            ?? Product::where('vendor_id', $vendor->id)->where('barcode', $code)
                ->when($productId, fn ($q) => $q->whereKeyNot($productId))
                ->value('id');

        if (! $owner) {
            return null;
        }

        $name = Product::whereKey($owner)->value('name');

        return "Already used by {$name}.";
    }

    /**
     * Points the product at the unit chosen under "Default unit at POS".
     *
     * After the save, because a unit added in the same save has no id until
     * then. The choice is 'base' or 'unit:{name}'. One that no longer matches a
     * unit falls back to the base unit. Shared with the Filament pages.
     */
    public static function applyDefaultUnit(Product $product, ?string $choice): void
    {
        $product = $product->fresh(['units']);
        $base = $product->units->firstWhere('is_base', true);

        $unit = null;

        if (is_string($choice) && str_starts_with($choice, 'unit:')) {
            $unit = $product->units->first(
                fn ($u) => ! $u->is_base && $u->is_active && $u->name === substr($choice, 5)
            );
        } elseif ($choice === null) {
            // The form said nothing: keep what is there, if it still exists.
            $unit = $product->units->firstWhere('id', $product->default_unit_id);
        }

        $unit ??= $base;

        if ($unit && (int) $product->default_unit_id !== (int) $unit->id) {
            // Straight to the table: bookkeeping, not an edit anyone should see
            // in the activity log or have re-run the unit and barcode sync.
            Product::whereKey($product->id)->update(['default_unit_id' => $unit->id]);
        }
    }

    // ── Internals ───────────────────────────────────────────────────────

    private function maxPhotos(Vendor $vendor): int
    {
        return $vendor->isFreePlan() ? FreePlanSetting::photosPerProduct() : 8;
    }

    /** Trimmed strings, blanks as null, lists as lists. */
    private function normalise(array $in): array
    {
        $clean = function ($v) use (&$clean) {
            if (is_array($v)) {
                return array_map($clean, $v);
            }
            if (is_string($v)) {
                $v = trim($v);

                return $v === '' ? null : $v;
            }

            return $v;
        };

        $in = $clean($in);

        foreach (['extraUnits', 'extraBaseBarcodes', 'tags', 'new_tags', 'specifications', 'photos', 'forget_words'] as $list) {
            $in[$list] = array_values(array_filter((array) ($in[$list] ?? []), fn ($v) => $v !== null));
        }

        foreach ($in['extraUnits'] as $i => $unit) {
            $in['extraUnits'][$i]['barcodes'] = array_values(array_filter((array) ($unit['barcodes'] ?? []), fn ($v) => $v !== null));
        }

        // An empty "Add barcode" row is not a barcode: dropped rather than refused.
        $in['extraBaseBarcodes'] = array_values($in['extraBaseBarcodes']);

        foreach (['sku_manual', 'shop_sold_out', 'is_service', 'track_serial', 'has_custom_price', 'allow_pos_price_override', 'show_in_pos', 'show_online'] as $flag) {
            if (array_key_exists($flag, $in)) {
                $in[$flag] = filter_var($in[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $in;
    }

    /** Every barcode on the form: in use once on it, and by no other product. */
    private function checkBarcodes($validator, array $in, int $vendorId, ?int $productId): void
    {
        $codes = [];

        if (filled($in['barcode'] ?? null)) {
            $codes['barcode'] = $in['barcode'];
        }
        foreach ($in['extraBaseBarcodes'] ?? [] as $i => $code) {
            $codes["extraBaseBarcodes.{$i}"] = $code;
        }
        foreach ($in['extraUnits'] ?? [] as $u => $unit) {
            foreach ($unit['barcodes'] ?? [] as $i => $code) {
                $codes["extraUnits.{$u}.barcodes.{$i}"] = $code;
            }
        }

        $seen = [];
        $vendor = Vendor::find($vendorId);

        foreach ($codes as $key => $code) {
            if (! is_string($code)) {
                continue;
            }
            if (isset($seen[$code])) {
                $validator->errors()->add($key, "Barcode {$code} is entered twice on this product.");

                continue;
            }
            $seen[$code] = true;

            if ($problem = $this->barcodeProblem($vendor, $code, $productId)) {
                $validator->errors()->add($key, "Barcode {$code}: {$problem}");
            }
        }
    }

    /** Photos: no more than the plan allows, and only this product's own. */
    private function checkPhotos($validator, array $in, ?Product $product, Vendor $vendor): void
    {
        $photos = $in['photos'] ?? [];
        $max = $this->maxPhotos($vendor);

        if (count($photos) > $max) {
            $validator->errors()->add('photos', "A product can have at most {$max} photos.");
        }

        $own = $product ? $product->getMedia('product-images')->pluck('uuid')->all() : [];

        foreach ($photos as $photo) {
            if (isset($photo['uuid']) && ! in_array($photo['uuid'], $own, true)) {
                $validator->errors()->add('photos', 'One of the photos no longer belongs to this product. Reload and try again.');

                return;
            }
        }
    }

    /** The columns on products this form writes. */
    private function productAttributes(array $in, Product $product, bool $creating, bool $canSeeCost, bool $linkedLocked): array
    {
        $num = fn ($key) => isset($in[$key]) ? (float) $in[$key] : null;
        $int = fn ($key) => isset($in[$key]) ? (int) round((float) $in[$key]) : null;

        $data = [
            'name'                    => $in['name'],
            'category_id'             => (int) $in['category_id'],
            'base_unit'               => $in['base_unit'],
            'wholesale_price'         => $num('wholesale_price'),
            'barcode'                 => $in['barcode'] ?? null,
            'brand'                   => $in['brand'] ?? null,
            'search_keywords'         => isset($in['search_keywords'])
                ? (implode(', ', SearchShortcuts::keywords($in['search_keywords'])) ?: null)
                : null,
            'supplier_id'             => isset($in['supplier_id']) ? (int) $in['supplier_id'] : null,
            'description'             => $in['description'] ?? null,
            'low_stock_threshold'     => $num('low_stock_threshold') ?? 5,
            'reorder_point'           => $int('reorder_point'),
            'preferred_quantity'      => $int('preferred_quantity'),
            'negative_stock_override' => isset($in['negative_stock_override']) ? (bool) (int) $in['negative_stock_override'] : null,
            'is_service'              => (bool) ($in['is_service'] ?? false),
            'show_in_pos'             => (bool) ($in['show_in_pos'] ?? true),
            'show_online'             => (bool) ($in['show_online'] ?? true),
        ];

        if (! $linkedLocked) {
            $data['price'] = (float) $in['price'];
        }

        if ($canSeeCost) {
            $data['cost_price'] = $num('cost_price');
        }

        if (ProductForm::usesHalfQuantities()) {
            $data['half_price'] = $num('half_price');
        }

        if (ProductForm::usesGadgetFields()) {
            $data['track_serial'] = (bool) ($in['track_serial'] ?? false);
            $data['specifications'] = collect($in['specifications'] ?? [])
                ->filter(fn ($row) => filled($row['key'] ?? null))
                ->mapWithKeys(fn ($row) => [$row['key'] => $row['value'] ?? ''])
                ->all() ?: null;
        }

        if ($product->isLinked()) {
            $data['has_custom_price'] = (bool) ($in['has_custom_price'] ?? false);
        }

        // Without a cost price there is no floor to haggle down to, so the
        // switch is locked and whatever was saved stays (as on the Filament form).
        $cost = $canSeeCost ? $num('cost_price') : $product->cost_price;
        if ($cost !== null) {
            $data['allow_pos_price_override'] = (bool) ($in['allow_pos_price_override'] ?? true);
        } elseif ($creating) {
            $data['allow_pos_price_override'] = true;
        }

        // Draft or Published. An archived product is restored from its own page,
        // never by saving the form.
        $status = $in['status'] ?? null;
        if ($creating) {
            $data['status'] = in_array($status, ['draft', 'published'], true)
                ? $status
                : 'draft';
        } elseif ($product->status !== 'archived' && in_array($status, ['draft', 'published'], true)) {
            $data['status'] = $status;
        }

        // The schedule only means something on a live product, and the form
        // only shows it then; otherwise what is saved is left alone.
        if (($data['status'] ?? $product->status) === 'published') {
            $data['published_at'] = $this->fromLocal($in['published_at'] ?? null);
            $data['unpublish_at'] = $this->fromLocal($in['unpublish_at'] ?? null);
        }

        return $data;
    }

    private function deleteRemovedBarcodesAndUnits(Product $product, array $in): void
    {
        $keepBase = collect($in['extraBaseBarcodes'] ?? [])->push($in['barcode'] ?? null)->filter()->all();

        ProductBarcode::where('product_id', $product->id)
            ->whereNull('product_unit_id')
            ->whereNotIn('barcode', $keepBase ?: [''])
            // The main barcode's row is kept in step by Product::syncMainBarcode.
            ->where('barcode', '!=', (string) $product->getRawOriginal('barcode'))
            ->delete();

        $incoming = collect($in['extraUnits'] ?? [])->keyBy(fn ($u) => (int) ($u['id'] ?? 0));

        foreach ($product->extraUnits()->get() as $unit) {
            if (! $incoming->has($unit->id)) {
                $unit->barcodes()->delete();
                $unit->delete();

                continue;
            }

            $unit->barcodes()->whereNotIn('barcode', $incoming[$unit->id]['barcodes'] ?: [''])->delete();
        }
    }

    private function saveUnitsAndBarcodes(Product $product, array $in): void
    {
        foreach (array_values($in['extraUnits'] ?? []) as $i => $row) {
            $values = [
                'name'              => $row['name'],
                'conversion_factor' => (int) $row['conversion_factor'],
                'price'             => (float) $row['price'],
                'wholesale_price'   => isset($row['wholesale_price']) ? (float) $row['wholesale_price'] : null,
                'is_active'         => filter_var($row['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'sort_order'        => $i + 1,
            ];

            if (ProductForm::usesHalfQuantities()) {
                $values['half_price'] = isset($row['half_price']) ? (float) $row['half_price'] : null;
            }

            $unit = isset($row['id'])
                ? ProductUnit::where('product_id', $product->id)->findOrFail($row['id'])
                : new ProductUnit(['product_id' => $product->id, 'vendor_id' => $product->vendor_id, 'is_base' => false]);

            $unit->fill($values)->save();

            $have = $unit->barcodes()->pluck('barcode')->all();
            foreach ($row['barcodes'] ?? [] as $code) {
                if (! in_array($code, $have, true)) {
                    $unit->barcodes()->create(['barcode' => $code, 'product_id' => $product->id, 'vendor_id' => $product->vendor_id]);
                }
            }
        }

        $have = ProductBarcode::where('product_id', $product->id)->whereNull('product_unit_id')->pluck('barcode')->all();
        foreach ($in['extraBaseBarcodes'] ?? [] as $code) {
            if (! in_array($code, $have, true)) {
                ProductBarcode::create([
                    'vendor_id'       => $product->vendor_id,
                    'product_id'      => $product->id,
                    'product_unit_id' => null,
                    'barcode'         => $code,
                ]);
            }
        }
    }

    /**
     * @param  array<int, array{uuid?: string, upload?: int}>  $order
     * @param  array<int, TemporaryUploadedFile>  $uploads
     */
    private function savePhotos(Product $product, array $order, array $uploads): void
    {
        $keep = collect($order)->pluck('uuid')->filter()->all();

        $product->getMedia('product-images')
            ->reject(fn (Media $m) => in_array($m->uuid, $keep, true))
            ->each(fn (Media $m) => $m->delete());

        $ids = [];

        foreach ($order as $photo) {
            if (isset($photo['uuid'])) {
                $ids[] = Media::where('uuid', $photo['uuid'])->value('id');

                continue;
            }

            $file = $uploads[(int) ($photo['upload'] ?? -1)] ?? null;

            if (! $file instanceof TemporaryUploadedFile || ! $file->exists()) {
                continue;
            }

            $ids[] = $product->addMediaFromString($file->get())
                ->usingFileName(Str::ulid().'.'.($file->getClientOriginalExtension() ?: 'jpg'))
                ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                ->withResponsiveImages()
                ->toMediaCollection('product-images')
                ->getKey();
        }

        $ids = array_values(array_filter($ids));

        if (count($ids) > 1) {
            Media::setNewOrder($ids);
        }
    }

    /** A stored UTC moment as the shop's wall clock, for a datetime-local field. */
    private function toLocal(?\DateTimeInterface $at): string
    {
        return $at ? Carbon::instance($at)->setTimezone($this->timezone())->format('Y-m-d\TH:i') : '';
    }

    /** What was typed, read in the shop's time zone and stored as UTC. */
    private function fromLocal(?string $value): ?Carbon
    {
        return filled($value) ? Carbon::parse($value, $this->timezone())->utc() : null;
    }

    private function timezone(): string
    {
        return config('reporting.timezone', 'Africa/Lagos');
    }
}
