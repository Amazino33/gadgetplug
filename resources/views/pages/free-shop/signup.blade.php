{{--
    /become-a-vendor (mode "create") and the owner's edit of the same
    application (mode "edit", for "more info" requests and resubmitting after a
    rejection). Two steps on the client; everything is validated again on the
    server when the form is finally sent. Built phone-first: most applicants
    fill this in on a phone.
--}}
@php
    use App\Services\FreePlan\FreeShopForm;

    $editing = $mode === 'edit';
    $prefill = $prefill ?? [];
    $value   = fn (string $field) => old($field, $prefill[$field] ?? '');

    $hasStepOneError = collect(FreeShopForm::STEP_ONE)->contains(fn ($f) => $errors->has($f));
    $startStep       = $errors->any() && ! $hasStepOneError ? 2 : 1;

    $photoOnFile = $editing && filled($vendor?->verification_photo);
    // What the Alpine component starts from, handed over as one @js() value.
    $formState = [
        'step'        => $startStep,
        'name'        => (string) $value('business_name'),
        'slug'        => (string) $value('slug'),
        'slugTouched' => filled($value('slug')),
        'description' => (string) $value('description'),
        'socialLink'  => (string) $value('social_link'),
        'photoOnFile' => $photoOnFile,
        'checkUrl'    => route('free-shop.check-slug'),
    ];

    $input    = 'w-full h-11 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border rounded-xl text-[14px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors';
    $border   = fn (string $field) => $errors->has($field) ? 'border-red-400' : 'border-[#d0d9d2] dark:border-[#2a3a2a]';
    $label    = 'block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5';
    $required = '<span class="text-red-400">*</span>';
@endphp

<x-layouts.auth-storefront :title="$editing ? 'Update your application' : 'List your business free'">

    @if ($signedInAs)
        <div class="text-center">
            <h1 class="font-montserrat font-black text-[20px] text-brand-dark dark:text-[#e8f5e9]">List your business free</h1>
            <p class="text-[13px] text-brand-muted mt-3 leading-relaxed">
                You're signed in as <strong>{{ $signedInAs->name }}</strong>. A shop needs its own account —
                sign out, then come back to this page to register your business.
            </p>
            <form method="POST" action="{{ route('logout') }}" class="mt-5">
                @csrf
                <button type="submit" class="w-full min-h-[48px] bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[13px] rounded-xl">Sign out</button>
            </form>
        </div>
    @else

    <div class="mb-5 text-center">
        @unless ($editing)
            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-brand/10 dark:bg-brand/20 border border-brand/20 text-brand text-[11px] font-semibold uppercase tracking-wider mb-3">
                Free for small businesses
            </div>
        @endunless
        <h1 class="font-montserrat font-black text-[20px] text-brand-dark dark:text-[#e8f5e9]">
            {{ $editing ? 'Update your application' : 'List your business free' }}
        </h1>
        <p class="text-[12px] text-brand-muted mt-1">
            {{ $editing ? 'Change what you need to, then send it back for review.' : 'Get your own shop link to send to customers on WhatsApp.' }}
        </p>
    </div>

    @if ($editing && filled($vendor->review_note))
        <div class="mb-5 rounded-xl border px-4 py-3 text-[12px] leading-relaxed
            {{ $vendor->hasStatus(\App\Models\Vendor::STATUS_REJECTED) ? 'bg-red-50 border-red-200 text-red-700 dark:bg-red-900/10 dark:border-red-800 dark:text-red-300' : 'bg-amber-50 border-amber-200 text-amber-800 dark:bg-amber-900/10 dark:border-amber-800 dark:text-amber-300' }}">
            <p class="font-bold mb-1">
                {{ $vendor->hasStatus(\App\Models\Vendor::STATUS_REJECTED) ? 'Why your application was not approved' : 'What we need from you' }}
            </p>
            <p class="whitespace-pre-line">{{ $vendor->review_note }}</p>
        </div>
    @endif

    <form method="POST"
          action="{{ $editing ? route('free-shop.application.update') : route('free-shop.store') }}"
          enctype="multipart/form-data"
          novalidate
          x-data="freeShopForm(@js($formState))"
          x-on:submit="submit($event)">
        @csrf
        @if ($editing) @method('PUT') @endif

        {{-- Honeypot. Off-screen rather than display:none, which some bots skip. --}}
        <div aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">
            <label>Company website <input type="text" name="{{ FreeShopForm::HONEYPOT }}" tabindex="-1" autocomplete="off"></label>
        </div>

        {{-- Step indicator --}}
        <div class="flex items-center gap-2 mb-5">
            <div class="flex-1 h-1.5 rounded-full bg-brand"></div>
            <div class="flex-1 h-1.5 rounded-full transition-colors" :class="step === 2 ? 'bg-brand' : 'bg-[#d0d9d2] dark:bg-[#2a3a2a]'"></div>
        </div>
        <p class="text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-4">
            Step <span x-text="step"></span> of 2 — <span x-text="step === 1 ? 'About your business' : 'Your shop'"></span>
        </p>

        @if ($errors->any())
            <div class="mb-4 bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-800 rounded-xl px-4 py-3 text-[12px] text-red-600 dark:text-red-400">
                Please check the highlighted fields.
                @if (! $editing || $errors->hasAny(['logo', 'verification_photo']))
                    Photos are not kept after an error — please choose them again.
                @endif
            </div>
        @endif

        {{-- ───────────── Step 1 — About your business ───────────── --}}
        <div x-ref="step1" x-show="step === 1" class="space-y-4">
            <div>
                <label for="business_name" class="{{ $label }}">Business name {!! $required !!}</label>
                <input id="business_name" name="business_name" type="text" required maxlength="100" autocomplete="organization"
                       x-model="name" x-on:input="suggestSlug()"
                       placeholder="e.g. Joy Gadgets" class="{{ $input }} {{ $border('business_name') }}">
                @error('business_name')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="owner_name" class="{{ $label }}">Your full name {!! $required !!}</label>
                <input id="owner_name" name="owner_name" type="text" required maxlength="100" autocomplete="name"
                       value="{{ $value('owner_name') }}" placeholder="e.g. Joy Okafor" class="{{ $input }} {{ $border('owner_name') }}">
                @error('owner_name')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="whatsapp" class="{{ $label }}">WhatsApp number {!! $required !!}</label>
                <input id="whatsapp" name="whatsapp" type="tel" inputmode="tel" required maxlength="20" autocomplete="tel"
                       value="{{ $errors->has('whatsapp') ? old('whatsapp') : \App\Support\WhatsAppNumber::local($value('whatsapp')) }}"
                       placeholder="0803 123 4567" class="{{ $input }} {{ $border('whatsapp') }}">
                <p class="text-[10px] text-brand-muted mt-1">Customers will message you on this number.</p>
                @error('whatsapp')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
            </div>

            @unless ($editing)
                <div>
                    <label for="email" class="{{ $label }}">Email <span class="normal-case font-normal">(optional)</span></label>
                    <input id="email" name="email" type="email" maxlength="255" autocomplete="email" inputmode="email"
                           value="{{ old('email') }}" placeholder="you@example.com" class="{{ $input }} {{ $border('email') }}">
                    <p class="text-[10px] text-brand-muted mt-1">No email? You'll sign in with your WhatsApp number.</p>
                    @error('email')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="{{ $label }}">Password {!! $required !!}</label>
                    <x-password-checklist name="password" placeholder="At least 8 characters" />
                    @error('password')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="password_confirmation" class="{{ $label }}">Confirm password {!! $required !!}</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                           placeholder="Repeat your password" class="{{ $input }} {{ $border('password_confirmation') }}">
                </div>
            @endunless

            <div>
                <label for="category" class="{{ $label }}">Category {!! $required !!}</label>
                <select id="category" name="category" required class="{{ $input }} {{ $border('category') }}">
                    <option value="">Select a category…</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category }}" @selected($value('category') === $category)>{{ $category }}</option>
                    @endforeach
                </select>
                @error('category')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="state" class="{{ $label }}">State {!! $required !!}</label>
                    <select id="state" name="state" required class="{{ $input }} {{ $border('state') }}">
                        <option value="">Select…</option>
                        @foreach ($states as $state)
                            <option value="{{ $state }}" @selected($value('state') === $state)>{{ $state }}</option>
                        @endforeach
                    </select>
                    @error('state')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="city_area" class="{{ $label }}">City / Area {!! $required !!}</label>
                    <input id="city_area" name="city_area" type="text" required maxlength="100"
                           value="{{ $value('city_area') }}" placeholder="e.g. Ikeja" class="{{ $input }} {{ $border('city_area') }}">
                    @error('city_area')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <button type="button" x-on:click="next()"
                    class="w-full min-h-[48px] flex items-center justify-center gap-2 bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[13px] rounded-xl transition-colors mt-2">
                Next — your shop
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
            </button>
        </div>

        {{-- ───────────── Step 2 — Your shop ───────────── --}}
        <div x-ref="step2" x-show="step === 2" x-cloak class="space-y-4">
            <div>
                <label for="slug" class="{{ $label }}">Shop link {!! $required !!}</label>
                <div class="flex items-stretch rounded-xl border overflow-hidden focus-within:border-brand {{ $border('slug') }}"
                     :class="slugState === 'taken' ? '!border-red-400' : (slugState === 'available' ? '!border-brand' : '')">
                    <span class="flex items-center px-3 bg-[#eef3ef] dark:bg-[#1a2a1a] text-[13px] text-brand-muted whitespace-nowrap">{{ $prefix }}</span>
                    <input id="slug" name="slug" type="text" required minlength="3" maxlength="40"
                           pattern="[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?" autocapitalize="none" autocomplete="off" spellcheck="false"
                           x-model="slug" x-on:input="slugTouched = true; slug = clean(slug); queueCheck()"
                           placeholder="joy-gadgets"
                           class="min-w-0 flex-1 h-11 px-2 bg-brand-bg dark:bg-[#0d1a0d] text-[14px] text-[#111] dark:text-[#e8f5e9] focus:outline-none">
                </div>
                <p class="text-[11px] mt-1 min-h-[16px]">
                    <span x-show="slugState === 'checking'" class="text-brand-muted">Checking…</span>
                    <span x-show="slugState === 'available'" class="text-brand font-semibold">available ✓</span>
                    <span x-show="slugState === 'taken'" class="text-red-500 font-semibold" x-text="'taken ✗ — ' + slugMessage"></span>
                    <span x-show="slugState === 'idle'" class="text-brand-muted">Lowercase letters, numbers and hyphens. 3–40 characters.</span>
                </p>
                @error('slug')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="description" class="{{ $label }}">Short description <span class="normal-case font-normal">(optional)</span></label>
                <textarea id="description" name="description" rows="3" maxlength="200" x-model="description"
                          placeholder="What do you sell? e.g. UK-used phones, chargers and accessories at good prices."
                          class="w-full px-3.5 py-2.5 bg-brand-bg dark:bg-[#0d1a0d] border rounded-xl text-[14px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors resize-none {{ $border('description') }}"></textarea>
                <p class="text-[10px] text-brand-muted mt-1 text-right"><span x-text="description.length"></span> / 200</p>
                @error('description')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
            </div>

            <div x-data="{ preview: @js($editing && $vendor->logo ? $vendor->logo_url : null) }">
                <label for="logo" class="{{ $label }}">Logo <span class="normal-case font-normal">(optional)</span></label>
                <div class="flex items-center gap-3">
                    <template x-if="preview"><img :src="preview" alt="" class="w-14 h-14 rounded-xl object-cover border border-[#d0d9d2] dark:border-[#2a3a2a]"></template>
                    <input id="logo" name="logo" type="file" accept="image/jpeg,image/png,image/webp"
                           x-on:change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : preview"
                           class="block w-full text-[12px] text-brand-muted file:mr-3 file:h-10 file:px-4 file:rounded-xl file:border-0 file:bg-brand/10 file:text-brand file:font-semibold">
                </div>
                @error('logo')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
            </div>

            {{-- Proof of business: a photo OR a social link, at least one. --}}
            <div class="rounded-xl border p-4 {{ $errors->hasAny(['verification_photo', 'social_link']) ? 'border-red-300 dark:border-red-800' : 'border-[#d0d9d2] dark:border-[#2a3a2a]' }}"
                 x-data="{ preview: null }">
                <p class="text-[12px] font-bold text-brand-dark dark:text-[#e8f5e9]">Proof of business {!! $required !!}</p>
                <p class="text-[11px] text-brand-muted mt-0.5 mb-3">
                    Helps us verify your business. A photo of your shop, stall, or products works.
                    Add a photo, a social media link, or both.
                </p>

                <label for="verification_photo" class="{{ $label }}">Shop photo</label>
                @if ($photoOnFile)
                    <p class="text-[11px] text-brand mb-1.5">We already have your photo. Choose a new one only to replace it.</p>
                @endif
                <template x-if="preview"><img :src="preview" alt="" class="mb-2 w-full max-h-40 rounded-xl object-cover"></template>
                <input id="verification_photo" name="verification_photo" type="file" accept="image/jpeg,image/png,image/webp"
                       x-ref="photo" x-on:change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null; $dispatch('proof-changed')"
                       class="block w-full text-[12px] text-brand-muted file:mr-3 file:h-10 file:px-4 file:rounded-xl file:border-0 file:bg-brand/10 file:text-brand file:font-semibold">
                @error('verification_photo')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror

                <div class="flex items-center gap-2 my-3 text-[10px] text-brand-muted uppercase tracking-wider">
                    <span class="flex-1 h-px bg-[#d0d9d2] dark:bg-[#2a3a2a]"></span> or <span class="flex-1 h-px bg-[#d0d9d2] dark:bg-[#2a3a2a]"></span>
                </div>

                <label for="social_link" class="{{ $label }}">Instagram / Facebook / TikTok link</label>
                <input id="social_link" name="social_link" type="url" inputmode="url" maxlength="255" autocapitalize="none"
                       x-model="socialLink" placeholder="https://instagram.com/yourshop" class="{{ $input }} {{ $border('social_link') }}">
                @error('social_link')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror

                <p x-show="proofMissing" x-cloak class="text-red-500 text-[11px] mt-2">Add a shop photo or a social media link.</p>
            </div>

            @unless ($editing)
                <label class="flex items-start gap-2.5 cursor-pointer">
                    <input name="terms" type="checkbox" value="1" required @checked(old('terms'))
                           class="mt-0.5 w-5 h-5 rounded border-[#d0d9d2] text-brand focus:ring-brand">
                    <span class="text-[12px] text-brand-muted leading-relaxed">
                        I agree to GadgetPlug's terms and <a href="{{ route('privacy-policy') }}" target="_blank" class="text-brand font-semibold underline">privacy policy</a>. {!! $required !!}
                    </span>
                </label>
                @error('terms')<p class="text-red-500 text-[11px] -mt-2">{{ $message }}</p>@enderror
            @endunless

            <div class="flex gap-3 pt-1">
                <button type="button" x-on:click="step = 1; window.scrollTo({ top: 0 })"
                        class="min-h-[48px] px-5 border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] font-semibold text-brand-dark dark:text-[#e8f5e9]">
                    Back
                </button>
                <button type="submit" x-bind:disabled="submitting"
                        class="flex-1 min-h-[48px] flex items-center justify-center bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[13px] rounded-xl transition-colors disabled:opacity-75">
                    <span x-show="! submitting">{{ $editing ? 'Send for review' : 'Create my shop' }}</span>
                    <span x-show="submitting" x-cloak>Sending…</span>
                </button>
            </div>
        </div>
    </form>

    @unless ($editing)
        <p class="mt-5 text-center text-[12px] text-brand-muted">
            Already have a shop?
            <a href="{{ route('login') }}" class="text-brand font-semibold hover:underline">Sign in</a>
        </p>
    @endunless

    <script>
        function freeShopForm(init) {
            return {
                ...init,
                submitting: false,
                proofMissing: false,
                slugState: 'idle',
                slugMessage: '',
                timer: null,

                init() {
                    if (this.slug) this.queueCheck();
                    this.$el.addEventListener('proof-changed', () => this.proofMissing = false);
                    this.$watch('socialLink', () => this.proofMissing = false);
                },

                clean(value) {
                    return value.toLowerCase()
                        .normalize('NFKD').replace(/[̀-ͯ]/g, '')
                        .replace(/['’]/g, '')
                        .replace(/[^a-z0-9]+/g, '-')
                        .replace(/^-+/, '')
                        .slice(0, 40);
                },

                suggestSlug() {
                    if (this.slugTouched) return;
                    this.slug = this.clean(this.name).replace(/-+$/, '');
                    this.queueCheck();
                },

                queueCheck() {
                    clearTimeout(this.timer);
                    const slug = this.slug.replace(/-+$/, '');
                    if (slug.length < 3) { this.slugState = 'idle'; return; }
                    this.slugState = 'checking';
                    this.timer = setTimeout(() => this.check(slug), 450);
                },

                async check(slug) {
                    try {
                        const res = await fetch(this.checkUrl + '?slug=' + encodeURIComponent(slug), { headers: { 'Accept': 'application/json' } });
                        if (res.status === 429) { this.slugState = 'idle'; return; }
                        const data = await res.json();
                        if (data.slug !== this.slug.replace(/-+$/, '')) return; // typed on since
                        this.slugState = data.available ? 'available' : 'taken';
                        this.slugMessage = data.message;
                    } catch (e) {
                        this.slugState = 'idle';
                    }
                },

                valid(ref) {
                    for (const el of this.$refs[ref].querySelectorAll('input, select, textarea')) {
                        if (el.type === 'file' || el.closest('[aria-hidden]')) continue;
                        if (! el.checkValidity()) { el.reportValidity(); return false; }
                    }
                    return true;
                },

                next() {
                    if (! this.valid('step1')) return;
                    this.step = 2;
                    window.scrollTo({ top: 0 });
                },

                submit(event) {
                    this.slug = this.slug.replace(/-+$/, '');

                    if (! this.valid('step1')) { event.preventDefault(); this.step = 1; this.$nextTick(() => this.valid('step1')); return; }
                    if (this.step !== 2) { event.preventDefault(); this.step = 2; return; }
                    if (! this.valid('step2')) { event.preventDefault(); return; }

                    const hasPhoto = this.$refs.photo.files.length > 0 || this.photoOnFile;
                    if (! hasPhoto && ! this.socialLink.trim()) { event.preventDefault(); this.proofMissing = true; return; }
                    if (this.slugState === 'taken') { event.preventDefault(); document.getElementById('slug').focus(); return; }

                    this.submitting = true;
                },
            };
        }
    </script>
    @endif

</x-layouts.auth-storefront>
