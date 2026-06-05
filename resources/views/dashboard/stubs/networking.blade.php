<!DOCTYPE html>

<html class="dark" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>LaraKube - Networking &amp; Traefik</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;600&amp;family=JetBrains+Mono:wght@400;700&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    "colors": {
                        "surface-dim": "#0e1416",
                        "surface-container-high": "#252b2d",
                        "surface-bright": "#343a3c",
                        "on-tertiary-fixed-variant": "#653e00",
                        "on-surface-variant": "#bcc9cd",
                        "surface-container": "#1b2122",
                        "on-secondary-container": "#00311f",
                        "on-secondary": "#003824",
                        "primary": "#4cd7f6",
                        "secondary-container": "#00a572",
                        "on-primary": "#003640",
                        "surface-container-low": "#171d1e",
                        "on-primary-fixed-variant": "#004e5c",
                        "on-background": "#dee3e6",
                        "inverse-surface": "#dee3e6",
                        "surface-container-highest": "#303638",
                        "surface-variant": "#303638",
                        "error": "#ffb4ab",
                        "surface-container-lowest": "#090f11",
                        "on-surface": "#dee3e6",
                        "primary-fixed-dim": "#4cd7f6",
                        "on-tertiary": "#472a00",
                        "on-tertiary-fixed": "#2a1700",
                        "tertiary": "#ffb95f",
                        "on-error-container": "#ffdad6",
                        "primary-fixed": "#acedff",
                        "surface": "#0e1416",
                        "tertiary-fixed-dim": "#ffb95f",
                        "error-container": "#93000a",
                        "on-secondary-fixed-variant": "#005236",
                        "tertiary-fixed": "#ffddb8",
                        "on-primary-container": "#00424f",
                        "outline": "#869397",
                        "tertiary-container": "#e79400",
                        "inverse-primary": "#00687a",
                        "on-error": "#690005",
                        "background": "#0e1416",
                        "outline-variant": "#3d494c",
                        "on-secondary-fixed": "#002113",
                        "inverse-on-surface": "#2b3133",
                        "secondary": "#4edea3",
                        "primary-container": "#06b6d4",
                        "on-tertiary-container": "#563400",
                        "on-primary-fixed": "#001f26",
                        "secondary-fixed": "#6ffbbe",
                        "surface-tint": "#4cd7f6",
                        "secondary-fixed-dim": "#4edea3"
                    },
                    "borderRadius": {
                        "DEFAULT": "0.125rem",
                        "lg": "0.25rem",
                        "xl": "0.5rem",
                        "full": "0.75rem"
                    },
                    "spacing": {
                        "lg": "24px",
                        "md": "16px",
                        "gutter": "12px",
                        "xl": "32px",
                        "sm": "8px",
                        "base": "4px",
                        "xs": "4px"
                    },
                    "fontFamily": {
                        "headline-lg": ["Geist"],
                        "code-sm": ["JetBrains Mono"],
                        "label-caps": ["JetBrains Mono"],
                        "headline-md": ["Geist"],
                        "body-md": ["Geist"]
                    },
                    "fontSize": {
                        "headline-lg": ["30px", {"lineHeight": "36px", "letterSpacing": "-0.02em", "fontWeight": "600"}],
                        "code-sm": ["12px", {"lineHeight": "16px", "fontWeight": "400"}],
                        "label-caps": ["11px", {"lineHeight": "16px", "letterSpacing": "0.05em", "fontWeight": "700"}],
                        "headline-md": ["24px", {"lineHeight": "32px", "letterSpacing": "-0.01em", "fontWeight": "600"}],
                        "body-md": ["14px", {"lineHeight": "20px", "fontWeight": "400"}]
                    }
                }
            }
        }
    </script>
<style>
        .glowing-primary { filter: drop-shadow(0 0 8px #4cd7f6); }
        .glowing-secondary { filter: drop-shadow(0 0 8px #4edea3); }
        .glowing-error { filter: drop-shadow(0 0 8px #ffb4ab); }
        
        /* Custom scrollbar for terminal */
        .terminal-scroll::-webkit-scrollbar { width: 8px; }
        .terminal-scroll::-webkit-scrollbar-track { background: #000; }
        .terminal-scroll::-webkit-scrollbar-thumb { background: #3d494c; border-radius: 4px; }
        .terminal-scroll::-webkit-scrollbar-thumb:hover { background: #869397; }
    </style>
</head>
<body class="bg-background text-on-background font-body-md font-body-md m-0 p-0 overflow-hidden flex h-screen">
<!-- SideNavBar (Shared Component) -->
<nav class="bg-surface-container-low dark:bg-surface-container-low text-primary font-body-md text-body-md h-screen w-64 fixed left-0 top-0 border-r border-outline-variant flex flex-col py-lg z-50">
<div class="px-md mb-xl flex items-center gap-md">
<div class="w-8 h-8 rounded-full bg-primary-container flex items-center justify-center overflow-hidden">
<img alt="User Profile" class="w-full h-full object-cover" data-alt="A macro photograph of an abstract, dark obsidian surface with sharp, angular edges catching subtle rim lighting. The lighting is low-key, creating a moody, technical atmosphere. Accent colors of deep cyan and emerald reflect off the faceted surfaces, symbolizing high-performance technology and precise engineering." src="https://lh3.googleusercontent.com/aida-public/AB6AXuBGBtal4NR5Z-74_JNsArqmsIkJT_hGdyRJYzxM1UuNC3LQTkPndUQoItDcj8-HU4gv6QD_l4TAbmJvRd--OC7vkYvDwMToLFolbCM716PUsraMnCX51bg0zrYZKOVlR5jLOak23OpvMWRg69v1aISNVJcEly2sSSAiFWUz9XJSWeQgD2SGN7B7Oe0Q4TfM_X_fJ5c_DWzdk-PvCLr91qqqHTeb_c8GO9S4_-UliDnKdBrgpwsp9_gwogYrIa9NcRc6Go6RQFDfAik"/>
</div>
<div>
<h1 class="font-headline-md text-headline-md font-bold text-primary">LaraKube</h1>
<p class="font-code-sm text-code-sm text-on-surface-variant">Cluster: production-01</p>
</div>
</div>
<div class="flex-1 overflow-y-auto px-sm flex flex-col gap-xs">
<a class="flex items-center gap-md px-md py-sm rounded text-on-surface-variant hover:bg-surface-container-highest hover:text-on-surface transition-colors duration-200" href="#">
<span class="material-symbols-outlined text-[20px]">dashboard</span>
                Dashboard
            </a>
<a class="flex items-center gap-md px-md py-sm rounded text-on-surface-variant hover:bg-surface-container-highest hover:text-on-surface transition-colors duration-200" href="#">
<span class="material-symbols-outlined text-[20px]">inventory_2</span>
                Projects
            </a>
<!-- Active Tab -->
<a class="flex items-center gap-md px-md py-sm rounded text-primary bg-surface-container-high border-r-2 border-primary transition-colors duration-200" href="#">
<span class="material-symbols-outlined text-[20px]">hub</span>
                Networking
            </a>
<a class="flex items-center gap-md px-md py-sm rounded text-on-surface-variant hover:bg-surface-container-highest hover:text-on-surface transition-colors duration-200" href="#">
<span class="material-symbols-outlined text-[20px]">monitor_heart</span>
                System Health
            </a>
<a class="flex items-center gap-md px-md py-sm rounded text-on-surface-variant hover:bg-surface-container-highest hover:text-on-surface transition-colors duration-200" href="#">
<span class="material-symbols-outlined text-[20px]">settings</span>
                Settings
            </a>
</div>
<div class="px-md mt-auto mb-lg">
<button class="w-full py-sm px-md bg-primary text-on-primary font-bold rounded flex items-center justify-center gap-sm hover:opacity-90 transition-opacity">
                Deploy Project
            </button>
</div>
<div class="px-sm flex flex-col gap-xs border-t border-outline-variant pt-md mx-md">
<a class="flex items-center gap-md px-sm py-sm rounded text-on-surface-variant hover:bg-surface-container-highest hover:text-on-surface transition-colors duration-200" href="#">
<span class="material-symbols-outlined text-[20px]">menu_book</span>
                Docs
            </a>
<a class="flex items-center gap-md px-sm py-sm rounded text-on-surface-variant hover:bg-surface-container-highest hover:text-on-surface transition-colors duration-200" href="#">
<span class="material-symbols-outlined text-[20px]">contact_support</span>
                Support
            </a>
</div>
</nav>
<!-- Main Content Area Wrapper -->
<div class="ml-64 flex flex-col flex-1 h-screen w-full relative">
<!-- TopAppBar (Shared Component) -->
<header class="bg-surface-dim dark:bg-surface-dim text-primary font-headline-sm text-headline-sm fixed top-0 right-0 w-[calc(100%-16rem)] h-16 border-b border-outline-variant flex justify-between items-center px-xl z-40">
<div class="flex items-center gap-lg">
<h2 class="font-headline-md text-headline-md text-primary">LaraKube Ops</h2>
</div>
<div class="flex items-center gap-lg">
<!-- Search on Left as per JSON -->
<div class="relative group">
<span class="material-symbols-outlined absolute left-sm top-1/2 -translate-y-1/2 text-on-surface-variant group-focus-within:text-primary">search</span>
<input class="bg-surface-container-high border border-outline-variant rounded pl-xl pr-md py-xs text-body-md text-on-surface focus:outline-none focus:border-primary focus:ring-1 focus:ring-primary w-64 transition-all" placeholder="Search..." type="text"/>
</div>
<div class="flex items-center gap-sm text-on-surface-variant">
<button class="p-xs rounded hover:text-primary transition-opacity cursor-pointer"><span class="material-symbols-outlined">notifications</span></button>
<button class="p-xs rounded hover:text-primary transition-opacity cursor-pointer"><span class="material-symbols-outlined">dns</span></button>
<button class="p-xs rounded hover:text-primary transition-opacity cursor-pointer"><span class="material-symbols-outlined">terminal</span></button>
</div>
<button class="bg-primary-container/20 text-primary border border-primary px-md py-xs rounded font-bold hover:bg-primary hover:text-on-primary transition-colors text-body-md">
                    Quick Action
                </button>
<div class="w-8 h-8 rounded-full border border-outline-variant overflow-hidden ml-sm cursor-pointer hover:border-primary transition-colors">
<img alt="Admin Avatar" class="w-full h-full object-cover" data-alt="A high-contrast headshot of a technical user in profile, illuminated by cool cyan screen glow. The background is completely black. The lighting emphasizes a sharp jawline and focused expression, embodying a dark, serious, and precise engineering aesthetic." src="https://lh3.googleusercontent.com/aida-public/AB6AXuDXaILBb5rS_flkiYTpanCvPXuOYLpGHgAm7_S7u_gHb4oT5ltGZ_6npxkMgS2omE_ANKRsEnTccFPeV1BGK9qEiHXJWvGs7zPMkQy8gOCPBioVgIllb70_XlKywgZO-bHgLpOA4lHOlb21ql7Mvlp0wyHLqLslbMcge8TvoSMR-4ezp-nK1SB8mmoRltUZ5GG1aWuiP0BdNgzj7u6vmRwYy6IdB810zJe8n-RIdg1fuyZGh8AAwAOZGkPC9uD5p6swKYsZ4bZRFJw"/>
</div>
</div>
</header>
<!-- Scrollable Canvas -->
<main class="flex-1 overflow-y-auto mt-16 p-xl bg-background">
<div class="max-w-[1440px] mx-auto flex flex-col gap-xl">
<!-- Section 1: Global Traefik Status -->
<section>
<h3 class="font-label-caps text-label-caps text-on-surface-variant uppercase mb-md">Global Traefik Status</h3>
<div class="grid grid-cols-1 md:grid-cols-3 gap-md">
<!-- Controller Status -->
<div class="bg-surface-container-high border border-outline-variant rounded-lg p-md flex flex-col gap-sm">
<div class="flex justify-between items-center">
<span class="font-body-md text-body-md text-on-surface-variant">Controller Status</span>
<span class="material-symbols-outlined text-secondary">power</span>
</div>
<div class="flex items-center gap-sm mt-xs">
<div class="w-2 h-2 rounded-full bg-secondary glowing-secondary animate-pulse"></div>
<span class="font-headline-md text-headline-md text-on-surface">Active</span>
</div>
</div>
<!-- SSL Certificates -->
<div class="bg-surface-container-high border border-outline-variant rounded-lg p-md flex flex-col gap-sm">
<div class="flex justify-between items-center">
<span class="font-body-md text-body-md text-on-surface-variant">SSL Certificates</span>
<span class="material-symbols-outlined text-primary">lock</span>
</div>
<div class="mt-xs">
<span class="font-headline-md text-headline-md text-on-surface">Self-signed wildcard</span>
</div>
</div>
<!-- Entrypoints -->
<div class="bg-surface-container-high border border-outline-variant rounded-lg p-md flex flex-col gap-sm">
<div class="flex justify-between items-center">
<span class="font-body-md text-body-md text-on-surface-variant">Entrypoints</span>
<span class="material-symbols-outlined text-primary">login</span>
</div>
<div class="flex gap-md mt-xs font-code-sm text-code-sm text-primary">
<span class="bg-primary/10 px-sm py-xs rounded">web (80)</span>
<span class="bg-primary/10 px-sm py-xs rounded">websecure (443)</span>
</div>
</div>
</div>
</section>
<!-- Section 2: Traefik Controls -->
<section class="flex flex-wrap gap-md items-center py-md border-b border-t border-outline-variant">
<button class="bg-surface-container-high border border-outline-variant text-on-surface px-md py-sm rounded flex items-center gap-sm hover:border-primary hover:text-primary transition-colors">
<span class="material-symbols-outlined text-[18px]">build</span>
                        Setup/Reinstall
                    </button>
<button class="bg-surface-container-high border border-outline-variant text-on-surface px-md py-sm rounded flex items-center gap-sm hover:border-primary hover:text-primary transition-colors">
<span class="material-symbols-outlined text-[18px]">restart_alt</span>
                        Restart Controller
                    </button>
<button class="bg-surface-container-high border border-outline-variant text-on-surface px-md py-sm rounded flex items-center gap-sm hover:border-primary hover:text-primary transition-colors">
<span class="material-symbols-outlined text-[18px]">open_in_new</span>
                        View Dashboard
                    </button>
<div class="flex-1"></div>
<button class="bg-surface-container border border-error text-error px-md py-sm rounded flex items-center gap-sm hover:bg-error/10 transition-colors">
<span class="material-symbols-outlined text-[18px]">delete_forever</span>
                        Destroy Stack
                    </button>
</section>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-xl">
<!-- Left Column: Routes & Terminal -->
<div class="lg:col-span-2 flex flex-col gap-xl">
<!-- Section 3: Ingress Routes Table -->
<section class="bg-surface-container-high border border-outline-variant rounded-lg overflow-hidden flex flex-col">
<div class="p-md border-b border-outline-variant flex justify-between items-center bg-surface-container">
<h3 class="font-headline-sm text-headline-sm text-on-surface">Ingress Routes</h3>
<button class="text-primary hover:text-primary-fixed transition-colors"><span class="material-symbols-outlined">refresh</span></button>
</div>
<div class="overflow-x-auto">
<table class="w-full text-left border-collapse">
<thead>
<tr class="bg-surface-dim font-label-caps text-label-caps text-on-surface-variant uppercase">
<th class="p-sm pl-md font-normal border-b border-outline-variant">Name</th>
<th class="p-sm font-normal border-b border-outline-variant">Host</th>
<th class="p-sm font-normal border-b border-outline-variant">Service</th>
<th class="p-sm pr-md font-normal border-b border-outline-variant text-right">SSL Status</th>
</tr>
</thead>
<tbody class="font-code-sm text-code-sm text-on-surface">
<tr class="hover:bg-surface-container-highest transition-colors group border-b border-outline-variant">
<td class="p-base pl-md py-sm text-primary">api-gateway</td>
<td class="p-base py-sm">api-gateway.dev.test</td>
<td class="p-base py-sm">laravel-web</td>
<td class="p-base pr-md py-sm text-right">
<span class="inline-flex items-center gap-xs bg-secondary/10 text-secondary px-xs py-xs rounded">
<span class="w-1.5 h-1.5 rounded-full bg-secondary"></span> Secure
                                                </span>
</td>
</tr>
<tr class="hover:bg-surface-container-highest transition-colors group border-b border-outline-variant">
<td class="p-base pl-md py-sm text-primary">auth-service</td>
<td class="p-base py-sm">auth.local.dev</td>
<td class="p-base py-sm">identity-svc</td>
<td class="p-base pr-md py-sm text-right">
<span class="inline-flex items-center gap-xs bg-secondary/10 text-secondary px-xs py-xs rounded">
<span class="w-1.5 h-1.5 rounded-full bg-secondary"></span> Secure
                                                </span>
</td>
</tr>
<tr class="hover:bg-surface-container-highest transition-colors group">
<td class="p-base pl-md py-sm text-primary">legacy-admin</td>
<td class="p-base py-sm">admin.dev.local</td>
<td class="p-base py-sm">php-fpm-legacy</td>
<td class="p-base pr-md py-sm text-right">
<span class="inline-flex items-center gap-xs bg-outline-variant/30 text-on-surface-variant px-xs py-xs rounded">
<span class="w-1.5 h-1.5 rounded-full bg-on-surface-variant"></span> Insecure
                                                </span>
</td>
</tr>
</tbody>
</table>
</div>
</section>
<!-- Section 4: Live Traffic Log -->
<section class="flex flex-col gap-md">
<h3 class="font-label-caps text-label-caps text-on-surface-variant uppercase flex items-center gap-sm">
<span class="material-symbols-outlined text-[16px]">terminal</span> Live Traffic Log
                            </h3>
<div class="bg-black border border-outline-variant rounded-lg p-md h-64 overflow-y-auto terminal-scroll font-code-sm text-code-sm">
<div class="text-on-surface-variant/50 mb-sm">-- Traefik Access Log Tailing Started --</div>
<div class="mb-xs"><span class="text-on-surface-variant/50">[10:42:01.124]</span> <span class="text-secondary">[200]</span> GET /api/v1/user <span class="text-primary ml-sm">api-gateway.dev.test</span></div>
<div class="mb-xs"><span class="text-on-surface-variant/50">[10:42:01.350]</span> <span class="text-secondary">[200]</span> GET /api/v1/user/preferences <span class="text-primary ml-sm">api-gateway.dev.test</span></div>
<div class="mb-xs"><span class="text-on-surface-variant/50">[10:42:05.881]</span> <span class="text-error">[404]</span> GET /favicon.ico <span class="text-primary ml-sm">auth.local.dev</span></div>
<div class="mb-xs"><span class="text-on-surface-variant/50">[10:42:12.002]</span> <span class="text-secondary">[201]</span> POST /auth/login <span class="text-primary ml-sm">auth.local.dev</span></div>
<div class="mb-xs"><span class="text-on-surface-variant/50">[10:42:15.440]</span> <span class="text-tertiary">[301]</span> GET /old-route <span class="text-primary ml-sm">admin.dev.local</span></div>
<div class="mb-xs"><span class="text-on-surface-variant/50">[10:42:18.991]</span> <span class="text-secondary">[200]</span> GET /api/v1/dashboard/stats <span class="text-primary ml-sm">api-gateway.dev.test</span></div>
<div class="mt-sm flex items-center gap-xs">
<span class="w-2 h-4 bg-primary animate-pulse"></span>
</div>
</div>
</section>
</div>
<!-- Right Column: Settings & Trust -->
<div class="lg:col-span-1 flex flex-col gap-xl">
<!-- Section 5: SSL Trust Section -->
<section class="bg-surface-container-high border border-outline-variant rounded-lg p-lg relative overflow-hidden">
<!-- Subtle decorative background element -->
<div class="absolute -right-12 -top-12 opacity-10 pointer-events-none">
<span class="material-symbols-outlined text-[120px] text-primary">verified_user</span>
</div>
<h3 class="font-headline-sm text-headline-sm text-on-surface mb-md relative z-10">LaraKube Local CA</h3>
<div class="bg-surface-dim border border-outline-variant rounded p-sm flex items-center gap-sm mb-md relative z-10">
<span class="material-symbols-outlined text-secondary">check_circle</span>
<span class="font-body-md text-body-md text-secondary">Trusted on this machine</span>
</div>
<p class="font-body-md text-body-md text-on-surface-variant mb-lg relative z-10 leading-relaxed">
                                Manage the local Certificate Authority used for dev.test domains. Trusting this CA prevents browser security warnings for local development environments.
                            </p>
<div class="flex flex-col gap-md relative z-10">
<button class="w-full bg-surface-container border border-outline-variant text-on-surface px-md py-sm rounded flex items-center justify-center gap-sm hover:border-primary hover:text-primary transition-colors">
<span class="material-symbols-outlined text-[18px]">download</span>
                                    Download Certificate
                                </button>
<button class="w-full bg-primary text-on-primary font-bold px-md py-sm rounded flex items-center justify-center gap-sm hover:opacity-90 transition-opacity">
<span class="material-symbols-outlined text-[18px]">security</span>
                                    Trust CA
                                </button>
</div>
</section>
</div>
</div>
</div>
</main>
</div>
</body></html>