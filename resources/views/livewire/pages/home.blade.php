<?php

use function Livewire\Volt\{state, layout};

?>

<div class="min-h-screen bg-gray-950 text-white font-sans selection:bg-blue-500 selection:text-white">
    <nav class="container mx-auto px-6 py-6 flex justify-between items-center">
        <div class="text-2xl font-black tracking-tighter">GADGET<span class="text-blue-500">PLUG</span></div>
        <a href="mailto:hello@gadgetplug.com" class="text-sm font-medium text-gray-400 hover:text-blue-400 transition-colors">Contact Us</a>
    </nav>

    <main class="container mx-auto px-6 pt-20 pb-12 flex flex-col items-center text-center">
        <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-blue-500/10 text-blue-400 text-sm font-semibold mb-8 border border-blue-500/20">
            <span class="relative flex h-2.5 w-2.5">
              <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
              <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-blue-500"></span>
            </span>
            Platform Launching Soon
        </div>

        <h1 class="text-5xl md:text-7xl font-bold tracking-tight mb-6 max-w-4xl">
            The next generation of <br class="hidden md:block" />
            <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-cyan-300">tech retail</span> is loading.
        </h1>

        <p class="text-gray-400 text-lg md:text-xl max-w-2xl mb-12">
            We are building the ultimate multi-vendor destination for premium gadgets, accessories, and next-level tech gear.
        </p>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 w-full max-w-5xl text-left mt-8">
            <div class="bg-gray-900/50 backdrop-blur-sm border border-white/5 rounded-3xl p-8 hover:border-white/10 transition-colors">
                <h3 class="text-xl font-bold mb-2">Smart Devices</h3>
                <p class="text-gray-500 text-sm leading-relaxed">A curated selection of the latest wearables and smart home technology.</p>
            </div>
            <div class="bg-gray-900/50 backdrop-blur-sm border border-white/5 rounded-3xl p-8 hover:border-white/10 transition-colors">
                <h3 class="text-xl font-bold mb-2">Pro Audio</h3>
                <p class="text-gray-500 text-sm leading-relaxed">High-fidelity sound equipment, headphones, and gear for audiophiles.</p>
            </div>
            <div class="bg-gray-900/50 backdrop-blur-sm border border-white/5 rounded-3xl p-8 hover:border-white/10 transition-colors">
                <h3 class="text-xl font-bold mb-2">Gaming Gear</h3>
                <p class="text-gray-500 text-sm leading-relaxed">Next-level peripherals and accessories to elevate any setup.</p>
            </div>
        </div>
    </main>
</div>
