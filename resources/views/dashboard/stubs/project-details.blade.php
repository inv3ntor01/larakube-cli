<!DOCTYPE html>

<html class="dark" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>LaraKube - API Gateway</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com" rel="preconnect"/>
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;600&amp;family=JetBrains+Mono:wght@400;700&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "on-error": "#690005",
                        "inverse-primary": "#00687a",
                        "surface-container": "#1b2122",
                        "primary-container": "#06b6d4",
                        "outline": "#869397",
                        "on-tertiary-fixed-variant": "#653e00",
                        "primary-fixed": "#acedff",
                        "surface-tint": "#4cd7f6",
                        "on-secondary-fixed": "#002113",
                        "tertiary-fixed-dim": "#ffb95f",
                        "surface": "#0e1416",
                        "on-secondary-container": "#00311f",
                        "on-secondary": "#003824",
                        "on-primary-fixed-variant": "#004e5c",
                        "surface-container-highest": "#303638",
                        "primary": "#4cd7f6",
                        "surface-container-low": "#171d1e",
                        "on-primary-fixed": "#001f26",
                        "inverse-surface": "#dee3e6",
                        "surface-dim": "#0e1416",
                        "on-error-container": "#ffdad6",
                        "on-surface-variant": "#bcc9cd",
                        "error": "#ffb4ab",
                        "surface-variant": "#303638",
                        "primary-fixed-dim": "#4cd7f6",
                        "on-secondary-fixed-variant": "#005236",
                        "error-container": "#93000a",
                        "secondary-fixed-dim": "#4edea3",
                        "on-surface": "#dee3e6",
                        "on-primary": "#003640",
                        "secondary-fixed": "#6ffbbe",
                        "secondary-container": "#00a572",
                        "background": "#0e1416",
                        "surface-bright": "#343a3c",
                        "on-tertiary-container": "#563400",
                        "on-background": "#dee3e6",
                        "inverse-on-surface": "#2b3133",
                        "tertiary-container": "#e79400",
                        "surface-container-high": "#252b2d",
                        "tertiary-fixed": "#ffddb8",
                        "surface-container-lowest": "#090f11",
                        "tertiary": "#ffb95f",
                        "outline-variant": "#3d494c",
                        "on-tertiary": "#472a00",
                        "on-primary-container": "#00424f",
                        "on-tertiary-fixed": "#2a1700",
                        "secondary": "#4edea3"
                    },
                    borderRadius: {
                        "DEFAULT": "0.125rem",
                        "lg": "0.25rem",
                        "xl": "0.5rem",
                        "full": "0.75rem"
                    },
                    spacing: {
                        "md": "16px",
                        "lg": "24px",
                        "sm": "8px",
                        "gutter": "12px",
                        "base": "4px",
                        "xl": "32px",
                        "xs": "4px"
                    },
                    fontFamily: {
                        "headline-md": ["Geist"],
                        "body-md": ["Geist"],
                        "headline-lg": ["Geist"],
                        "code-sm": ["JetBrains Mono"],
                        "label-caps": ["JetBrains Mono"]
                    },
                    fontSize: {
                        "headline-md": ["24px", { lineHeight: "32px", letterSpacing: "-0.01em", fontWeight: "600" }],
                        "body-md": ["14px", { lineHeight: "20px", fontWeight: "400" }],
                        "headline-lg": ["30px", { lineHeight: "36px", letterSpacing: "-0.02em", fontWeight: "600" }],
                        "code-sm": ["12px", { lineHeight: "16px", fontWeight: "400" }],
                        "label-caps": ["11px", { lineHeight: "16px", letterSpacing: "0.05em", fontWeight: "700" }]
                    }
                }
            }
        }
    </script>
<style>
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .text-glow-secondary { text-shadow: 0 0 8px rgba(78, 222, 163, 0.6); }
        .bg-glow-secondary { box-shadow: 0 0 8px rgba(78, 222, 163, 0.6); }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #0e1416; }
        ::-webkit-scrollbar-thumb { background: #3d494c; border-radius: 2px; }
        ::-webkit-scrollbar-thumb:hover { background: #869397; }
    </style>
</head>
<body class="bg-background text-on-background font-body-md text-body-md overflow-hidden flex h-screen selection:bg-primary/30">
<!-- Shared Component: SideNavBar -->
<aside class="bg-surface text-primary font-body-md text-body-md h-screen w-64 fixed left-0 top-0 border-r border-outline-variant flat no shadows flex flex-col h-full py-lg px-md gap-md z-50">
<div class="flex items-center gap-sm mb-lg">
<span class="material-symbols-outlined font-headline-md text-headline-md font-bold text-primary">hexagon</span>
<div>
<div class="font-headline-md text-headline-md font-bold text-primary tracking-tight">LaraKube</div>
<div class="font-code-sm text-code-sm text-on-surface-variant">v2.4.0-stable</div>
</div>
</div>
<button class="w-full bg-primary text-on-primary py-sm px-md rounded-DEFAULT font-label-caps text-label-caps mb-lg hover:opacity-90 transition-opacity flex items-center justify-center gap-xs">
<span class="material-symbols-outlined text-[16px]">add</span>
            New Cluster
        </button>
<nav class="flex-1 flex flex-col gap-base">
<a class="flex items-center gap-sm px-md py-sm rounded-DEFAULT text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface transition-colors" href="#">
<span class="material-symbols-outlined">dashboard</span>
                Dashboard
            </a>
<!-- Active State Navigation: Projects -->
<a class="flex items-center gap-sm px-md py-sm rounded-DEFAULT text-primary font-bold bg-primary/10 border-r-2 border-primary Active: opacity-80 scale-95 transition-all" href="#">
<span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">account_tree</span>
                Projects
            </a>
<a class="flex items-center gap-sm px-md py-sm rounded-DEFAULT text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface transition-colors" href="#">
<span class="material-symbols-outlined">lan</span>
                Networking
            </a>
<a class="flex items-center gap-sm px-md py-sm rounded-DEFAULT text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface transition-colors" href="#">
<span class="material-symbols-outlined">monitor_heart</span>
                System Health
            </a>
<a class="flex items-center gap-sm px-md py-sm rounded-DEFAULT text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface transition-colors" href="#">
<span class="material-symbols-outlined">settings</span>
                Settings
            </a>
</nav>
<div class="mt-auto flex flex-col gap-base pt-md border-t border-outline-variant">
<a class="flex items-center gap-sm px-md py-sm rounded-DEFAULT text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface transition-colors" href="#">
<span class="material-symbols-outlined">menu_book</span>
                Documentation
            </a>
<a class="flex items-center gap-sm px-md py-sm rounded-DEFAULT text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface transition-colors" href="#">
<span class="material-symbols-outlined">support_agent</span>
                Support
            </a>
</div>
</aside>
<!-- Main Content Wrapper -->
<div class="ml-64 w-[calc(100%-16rem)] h-screen flex flex-col relative">
<!-- Shared Component: TopAppBar -->
<header class="bg-surface-dim text-primary font-label-caps text-label-caps fixed top-0 right-0 w-[calc(100%-16rem)] h-16 border-b border-outline-variant flat no shadows flex justify-between items-center px-lg z-40">
<div class="flex items-center gap-lg h-full">
<nav class="flex gap-lg h-full items-center">
<a class="text-on-surface-variant hover:text-primary transition-opacity flex items-center h-full" href="#">Mainframe</a>
<!-- Active Context in Top Nav if applicable, otherwise default hover -->
<a class="text-primary border-b-2 border-primary h-full flex items-center px-xs pt-1" href="#">Clusters</a>
<a class="text-on-surface-variant hover:text-primary transition-opacity flex items-center h-full" href="#">Nodes</a>
</nav>
</div>
<div class="flex items-center gap-md">
<div class="flex items-center gap-sm text-on-surface-variant border-r border-outline-variant pr-md">
<span class="material-symbols-outlined text-[16px]">commit</span>
                    Branch: main
                </div>
<div class="flex items-center gap-sm text-on-surface-variant">
<button class="hover:text-primary transition-colors p-xs Focus: ring-1 ring-primary outline-none rounded-DEFAULT"><span class="material-symbols-outlined">notifications</span></button>
<button class="hover:text-primary transition-colors p-xs Focus: ring-1 ring-primary outline-none rounded-DEFAULT"><span class="material-symbols-outlined">settings_input_component</span></button>
<button class="hover:text-primary transition-colors p-xs Focus: ring-1 ring-primary outline-none rounded-DEFAULT"><span class="material-symbols-outlined">terminal</span></button>
</div>
<button class="bg-primary/10 text-primary border border-primary/30 px-md py-xs rounded-DEFAULT hover:bg-primary/20 transition-colors ml-sm">
                    Deploy
                </button>
<div class="w-8 h-8 rounded-full bg-surface-container-high border border-outline-variant overflow-hidden ml-sm flex items-center justify-center">
<span class="material-symbols-outlined text-on-surface-variant text-[20px]">person</span>
</div>
</div>
</header>
<!-- Canvas Canvas -->
<main class="mt-16 p-lg flex-1 overflow-y-auto flex flex-col gap-lg bg-background">
<!-- Header Section -->
<section class="flex justify-between items-start">
<div class="flex flex-col gap-sm">
<div class="flex items-center gap-md">
<h1 class="font-headline-lg text-headline-lg text-on-surface">API Gateway</h1>
<div class="flex items-center gap-xs px-sm py-xs bg-secondary/10 border border-secondary/20 rounded-DEFAULT">
<span class="w-1.5 h-1.5 rounded-full bg-secondary bg-glow-secondary"></span>
<span class="font-label-caps text-label-caps text-secondary text-glow-secondary">UP/Healthy</span>
</div>
</div>
<div class="font-code-sm text-code-sm text-on-surface-variant flex items-center gap-md">
<span>ID: prj-9a8b7c</span>
<span class="text-outline-variant">|</span>
<span>Region: us-east-1a</span>
<span class="text-outline-variant">|</span>
<span>Uptime: 14d 6h</span>
</div>
</div>
<div class="flex gap-sm">
<button class="px-md py-sm border border-outline-variant text-on-surface hover:bg-surface-container hover:text-error transition-colors rounded-DEFAULT font-label-caps text-label-caps flex items-center gap-xs">
<span class="material-symbols-outlined text-[16px]">stop</span> Stop
                    </button>
<button class="px-md py-sm border border-outline-variant text-on-surface hover:bg-surface-container hover:text-primary transition-colors rounded-DEFAULT font-label-caps text-label-caps flex items-center gap-xs">
<span class="material-symbols-outlined text-[16px]">restart_alt</span> Restart
                    </button>
<button class="px-md py-sm bg-primary text-on-primary hover:bg-surface-tint transition-colors rounded-DEFAULT font-label-caps text-label-caps flex items-center gap-xs">
<span class="material-symbols-outlined text-[16px]">healing</span> Heal
                    </button>
</div>
</section>
<!-- Tabs -->
<div class="border-b border-outline-variant flex gap-lg">
<button class="pb-sm font-label-caps text-label-caps text-primary border-b-2 border-primary">Blueprint</button>
<button class="pb-sm font-label-caps text-label-caps text-on-surface-variant hover:text-on-surface transition-colors">Environment</button>
<button class="pb-sm font-label-caps text-label-caps text-on-surface-variant hover:text-on-surface transition-colors">Services</button>
<button class="pb-sm font-label-caps text-label-caps text-on-surface-variant hover:text-on-surface transition-colors">Logs</button>
</div>
<!-- Active Tab Content: Blueprint -->
<section class="flex gap-md h-[calc(100vh-16rem)]">
<!-- Code Editor -->
<div class="flex-1 bg-[#000000] border border-outline-variant rounded-DEFAULT flex flex-col overflow-hidden relative">
<!-- Editor Header -->
<div class="bg-surface-container-low border-b border-outline-variant px-md py-xs flex justify-between items-center">
<div class="flex items-center gap-sm">
<span class="material-symbols-outlined text-[16px] text-primary">description</span>
<span class="font-code-sm text-code-sm text-on-surface">.larakube.json</span>
</div>
<button class="text-on-surface-variant hover:text-primary transition-colors">
<span class="material-symbols-outlined text-[16px]">content_copy</span>
</button>
</div>
<!-- Editor Body -->
<div class="flex-1 overflow-auto p-md font-code-sm text-code-sm leading-relaxed text-on-surface-variant">
<pre><code><span class="text-primary">{</span>
  <span class="text-[#4edea3]">"name"</span>: <span class="text-[#ffddb8]">"api-gateway"</span>,
  <span class="text-[#4edea3]">"type"</span>: <span class="text-[#ffddb8]">"laravel"</span>,
  <span class="text-[#4edea3]">"version"</span>: <span class="text-[#ffddb8]">"8.1"</span>,
  <span class="text-[#4edea3]">"replicas"</span>: <span class="text-primary">3</span>,
  <span class="text-[#4edea3]">"environment"</span>: <span class="text-primary">{</span>
    <span class="text-[#4edea3]">"APP_ENV"</span>: <span class="text-[#ffddb8]">"production"</span>,
    <span class="text-[#4edea3]">"CACHE_DRIVER"</span>: <span class="text-[#ffddb8]">"redis"</span>,
    <span class="text-[#4edea3]">"QUEUE_CONNECTION"</span>: <span class="text-[#ffddb8]">"sqs"</span>
  <span class="text-primary">}</span>,
  <span class="text-[#4edea3]">"resources"</span>: <span class="text-primary">{</span>
    <span class="text-[#4edea3]">"limits"</span>: <span class="text-primary">{</span>
      <span class="text-[#4edea3]">"cpu"</span>: <span class="text-[#ffddb8]">"500m"</span>,
      <span class="text-[#4edea3]">"memory"</span>: <span class="text-[#ffddb8]">"512Mi"</span>
    <span class="text-primary">}</span>
  <span class="text-primary">}</span>,
  <span class="text-[#4edea3]">"ingress"</span>: <span class="text-primary">{</span>
    <span class="text-[#4edea3]">"enabled"</span>: <span class="text-primary">true</span>,
    <span class="text-[#4edea3]">"hosts"</span>: <span class="text-primary">[</span>
      <span class="text-[#ffddb8]">"api.example.com"</span>
    <span class="text-primary">]</span>
  <span class="text-primary">}</span>
<span class="text-primary">}</span></code></pre>
</div>
</div>
<!-- Surgically Patch Sidebar -->
<aside class="w-80 bg-surface-container border border-outline-variant rounded-DEFAULT flex flex-col flex-shrink-0">
<div class="p-md border-b border-outline-variant flex justify-between items-center bg-surface-container-low rounded-t-DEFAULT">
<h3 class="font-label-caps text-label-caps text-on-surface">Surgically Patch</h3>
<span class="material-symbols-outlined text-[16px] text-primary">science</span>
</div>
<div class="p-md flex-1 overflow-y-auto flex flex-col gap-md">
<p class="font-body-md text-body-md text-on-surface-variant mb-sm">Select services to inject or update in the current blueprint without a full redeployment.</p>
<!-- Checkbox Item -->
<label class="flex items-start gap-md p-sm hover:bg-surface-container-high rounded-DEFAULT cursor-pointer transition-colors border border-transparent hover:border-outline-variant group">
<div class="relative flex items-center pt-0.5">
<input class="peer sr-only" type="checkbox"/>
<div class="w-4 h-4 border border-outline-variant rounded-sm bg-surface peer-checked:bg-primary peer-checked:border-primary transition-colors flex items-center justify-center">
<span class="material-symbols-outlined text-[12px] text-on-primary opacity-0 peer-checked:opacity-100 font-bold" style="font-variation-settings: 'wght' 700;">check</span>
</div>
</div>
<div class="flex flex-col">
<span class="font-code-sm text-code-sm text-on-surface group-hover:text-primary transition-colors">MySQL</span>
<span class="font-body-md text-[12px] text-on-surface-variant leading-tight mt-0.5">Relational DB Engine</span>
</div>
</label>
<!-- Checkbox Item -->
<label class="flex items-start gap-md p-sm hover:bg-surface-container-high rounded-DEFAULT cursor-pointer transition-colors border border-transparent hover:border-outline-variant group">
<div class="relative flex items-center pt-0.5">
<input checked="" class="peer sr-only" type="checkbox"/>
<div class="w-4 h-4 border border-outline-variant rounded-sm bg-surface peer-checked:bg-primary peer-checked:border-primary transition-colors flex items-center justify-center">
<span class="material-symbols-outlined text-[12px] text-on-primary opacity-0 peer-checked:opacity-100 font-bold" style="font-variation-settings: 'wght' 700;">check</span>
</div>
</div>
<div class="flex flex-col">
<span class="font-code-sm text-code-sm text-on-surface group-hover:text-primary transition-colors">Redis</span>
<span class="font-body-md text-[12px] text-on-surface-variant leading-tight mt-0.5">In-memory Cache &amp; Queue</span>
</div>
</label>
<!-- Checkbox Item -->
<label class="flex items-start gap-md p-sm hover:bg-surface-container-high rounded-DEFAULT cursor-pointer transition-colors border border-transparent hover:border-outline-variant group">
<div class="relative flex items-center pt-0.5">
<input class="peer sr-only" type="checkbox"/>
<div class="w-4 h-4 border border-outline-variant rounded-sm bg-surface peer-checked:bg-primary peer-checked:border-primary transition-colors flex items-center justify-center">
<span class="material-symbols-outlined text-[12px] text-on-primary opacity-0 peer-checked:opacity-100 font-bold" style="font-variation-settings: 'wght' 700;">check</span>
</div>
</div>
<div class="flex flex-col">
<span class="font-code-sm text-code-sm text-on-surface group-hover:text-primary transition-colors">Meilisearch</span>
<span class="font-body-md text-[12px] text-on-surface-variant leading-tight mt-0.5">Fast text search engine</span>
</div>
</label>
</div>
<div class="p-md border-t border-outline-variant bg-surface-container-low rounded-b-DEFAULT">
<button class="w-full bg-primary/10 text-primary border border-primary/30 hover:bg-primary/20 hover:border-primary transition-all py-sm rounded-DEFAULT font-label-caps text-label-caps">
                            Execute Patch
                        </button>
</div>
</aside>
</section>
</main>
</div>
</body></html>