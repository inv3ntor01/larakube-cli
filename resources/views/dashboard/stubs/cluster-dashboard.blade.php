<!DOCTYPE html>

<html class="dark" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>LaraKube CLI - Cluster Dashboard</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;600;700&amp;family=JetBrains+Mono:wght@400;700&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<script id="tailwind-config">
        tailwind.config = {
          darkMode: "class",
          theme: {
            extend: {
              "colors": {
                      "outline": "#869397",
                      "on-primary": "#003640",
                      "error": "#ffb4ab",
                      "on-primary-container": "#00424f",
                      "primary-fixed-dim": "#4cd7f6",
                      "on-tertiary": "#472a00",
                      "on-error-container": "#ffdad6",
                      "on-secondary-container": "#00311f",
                      "tertiary": "#ffb95f",
                      "secondary": "#4edea3",
                      "surface-variant": "#303638",
                      "on-tertiary-fixed": "#2a1700",
                      "secondary-fixed-dim": "#4edea3",
                      "background": "#0e1416",
                      "on-primary-fixed-variant": "#004e5c",
                      "on-error": "#690005",
                      "on-secondary": "#003824",
                      "on-background": "#dee3e6",
                      "primary-container": "#06b6d4",
                      "surface-container": "#1b2122",
                      "tertiary-fixed-dim": "#ffb95f",
                      "secondary-fixed": "#6ffbbe",
                      "primary-fixed": "#acedff",
                      "outline-variant": "#3d494c",
                      "inverse-on-surface": "#2b3133",
                      "primary": "#4cd7f6",
                      "on-primary-fixed": "#001f26",
                      "on-tertiary-container": "#563400",
                      "surface-bright": "#343a3c",
                      "surface-container-lowest": "#090f11",
                      "secondary-container": "#00a572",
                      "error-container": "#93000a",
                      "surface-dim": "#0e1416",
                      "on-surface": "#dee3e6",
                      "on-tertiary-fixed-variant": "#653e00",
                      "inverse-surface": "#dee3e6",
                      "on-secondary-fixed": "#002113",
                      "tertiary-container": "#e79400",
                      "surface-container-low": "#171d1e",
                      "tertiary-fixed": "#ffddb8",
                      "surface-tint": "#4cd7f6",
                      "on-secondary-fixed-variant": "#005236",
                      "surface-container-highest": "#303638",
                      "surface": "#0e1416",
                      "on-surface-variant": "#bcc9cd",
                      "inverse-primary": "#00687a",
                      "surface-container-high": "#252b2d"
              },
              "borderRadius": {
                      "DEFAULT": "0.125rem",
                      "lg": "0.25rem",
                      "xl": "0.5rem",
                      "full": "0.75rem"
              },
              "spacing": {
                      "sm": "8px",
                      "xl": "32px",
                      "lg": "24px",
                      "md": "16px",
                      "base": "4px",
                      "xs": "4px",
                      "gutter": "12px"
              },
              "fontFamily": {
                      "label-caps": [
                              "JetBrains Mono"
                      ],
                      "headline-md": [
                              "Geist"
                      ],
                      "headline-lg": [
                              "Geist"
                      ],
                      "code-sm": [
                              "JetBrains Mono"
                      ],
                      "body-md": [
                              "Geist"
                      ]
              },
              "fontSize": {
                      "label-caps": [
                              "11px",
                              {
                                      "lineHeight": "16px",
                                      "letterSpacing": "0.05em",
                                      "fontWeight": "700"
                              }
                      ],
                      "headline-md": [
                              "24px",
                              {
                                      "lineHeight": "32px",
                                      "letterSpacing": "-0.01em",
                                      "fontWeight": "600"
                              }
                      ],
                      "headline-lg": [
                              "30px",
                              {
                                      "lineHeight": "36px",
                                      "letterSpacing": "-0.02em",
                                      "fontWeight": "600"
                              }
                      ],
                      "code-sm": [
                              "12px",
                              {
                                      "lineHeight": "16px",
                                      "fontWeight": "400"
                              }
                      ],
                      "body-md": [
                              "14px",
                              {
                                      "lineHeight": "20px",
                                      "fontWeight": "400"
                              }
                      ]
              }
            }
          }
        }
    </script>
<style>
        /* Custom High-Density Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #3d494c; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #869397; }
        
        /* Functional Glows */
        .glow-cyan { filter: drop-shadow(0 0 8px rgba(76, 215, 246, 0.4)); }
        .glow-emerald { filter: drop-shadow(0 0 8px rgba(78, 222, 163, 0.4)); }
        .glow-amber { filter: drop-shadow(0 0 8px rgba(255, 185, 95, 0.4)); }
        
        /* Status Dot Animation */
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.8); }
        }
        .status-dot-pulse { animation: pulse-dot 2s infinite ease-in-out; }
    </style>
</head>
<body class="bg-background text-on-surface antialiased overflow-hidden select-none">
<!-- SideNavBar -->
<aside class="bg-surface-container dark:bg-surface-container font-body-md text-body-md h-screen w-64 fixed left-0 top-0 border-r border-outline-variant flex flex-col h-full py-md z-40">
<!-- Header -->
<div class="px-md pb-lg border-b border-outline-variant mb-sm flex items-center gap-sm">
<div class="w-8 h-8 rounded bg-primary-container flex items-center justify-center shrink-0">
<span class="material-symbols-outlined text-on-primary-container" data-icon="terminal" style="font-variation-settings: 'FILL' 1;">terminal</span>
</div>
<div>
<h1 class="font-headline-md text-headline-md font-bold text-primary truncate">LaraKube CLI</h1>
<p class="font-code-sm text-code-sm text-on-surface-variant truncate">v2.4.0-stable</p>
</div>
</div>
<!-- Navigation -->
<nav class="flex-1 overflow-y-auto px-sm flex flex-col gap-xs py-sm">
<a class="flex items-center gap-md px-md py-sm rounded-DEFAULT text-primary bg-primary/10 border-r-2 border-primary transition-colors duration-200" href="#">
<span class="material-symbols-outlined text-[20px]" data-icon="dashboard">dashboard</span>
<span>Dashboard</span>
</a>
<a class="flex items-center gap-md px-md py-sm rounded-DEFAULT text-on-surface-variant hover:bg-surface-variant/50 hover:text-on-surface transition-colors duration-200" href="#">
<span class="material-symbols-outlined text-[20px]" data-icon="inventory_2">inventory_2</span>
<span>Projects</span>
</a>
<a class="flex items-center gap-md px-md py-sm rounded-DEFAULT text-on-surface-variant hover:bg-surface-variant/50 hover:text-on-surface transition-colors duration-200" href="#">
<span class="material-symbols-outlined text-[20px]" data-icon="lan">lan</span>
<span>Networking</span>
</a>
<a class="flex items-center gap-md px-md py-sm rounded-DEFAULT text-on-surface-variant hover:bg-surface-variant/50 hover:text-on-surface transition-colors duration-200" href="#">
<span class="material-symbols-outlined text-[20px]" data-icon="monitor_heart">monitor_heart</span>
<span>System Health</span>
</a>
<a class="flex items-center gap-md px-md py-sm rounded-DEFAULT text-on-surface-variant hover:bg-surface-variant/50 hover:text-on-surface transition-colors duration-200 mt-auto" href="#">
<span class="material-symbols-outlined text-[20px]" data-icon="settings">settings</span>
<span>Settings</span>
</a>
</nav>
<!-- CTA -->
<div class="px-md pt-sm">
<button class="w-full bg-primary hover:bg-primary-fixed-dim text-on-primary font-label-caps text-label-caps py-sm px-md rounded-DEFAULT flex items-center justify-center gap-xs transition-colors">
<span class="material-symbols-outlined text-[16px]" data-icon="add">add</span>
                New Project
            </button>
</div>
</aside>
<!-- TopAppBar -->
<header class="bg-surface dark:bg-surface font-label-caps text-label-caps fixed top-0 right-0 left-64 h-16 border-b border-outline-variant flex items-center justify-between px-lg transition-all duration-300 z-30 w-[calc(100%-16rem)]">
<!-- Search -->
<div class="flex-1 flex items-center relative max-w-md">
<span class="material-symbols-outlined absolute left-sm text-on-surface-variant text-[18px]" data-icon="search">search</span>
<input class="w-full bg-surface-container-low border border-outline-variant rounded-DEFAULT py-xs pl-[32px] pr-sm font-code-sm text-code-sm text-on-surface placeholder:text-outline focus:outline-none focus:border-primary focus:ring-1 focus:ring-primary transition-colors" placeholder="Search clusters, logs..." type="text"/>
</div>
<!-- Right Actions -->
<div class="flex items-center gap-lg">
<div class="flex items-center gap-sm border-r border-outline-variant pr-md">
<div class="flex items-center gap-xs text-on-surface-variant bg-surface-container py-[2px] px-sm rounded border border-outline-variant">
<span class="w-2 h-2 rounded-full bg-secondary status-dot-pulse"></span>
<span>Traefik Active</span>
</div>
<div class="flex items-center gap-xs text-on-surface-variant bg-surface-container py-[2px] px-sm rounded border border-outline-variant">
<span class="w-2 h-2 rounded-full bg-secondary status-dot-pulse"></span>
<span>K3d Cluster Healthy</span>
</div>
</div>
<div class="flex items-center gap-sm">
<button class="w-8 h-8 flex items-center justify-center rounded text-on-surface-variant hover:text-primary hover:bg-surface-variant/50 transition-colors">
<span class="material-symbols-outlined text-[20px]" data-icon="account_tree">account_tree</span>
</button>
<button class="w-8 h-8 flex items-center justify-center rounded text-on-surface-variant hover:text-primary hover:bg-surface-variant/50 transition-colors relative">
<span class="material-symbols-outlined text-[20px]" data-icon="notifications">notifications</span>
<span class="absolute top-[4px] right-[6px] w-[6px] h-[6px] rounded-full bg-primary glow-cyan"></span>
</button>
</div>
</div>
</header>
<!-- Main Canvas -->
<main class="ml-64 mt-16 p-lg h-[calc(100vh-4rem)] overflow-y-auto">
<div class="max-w-[1600px] mx-auto flex flex-col gap-lg h-full">
<!-- Top Section: Grid & Quick Launch -->
<div class="grid grid-cols-12 gap-gutter shrink-0">
<!-- Active Projects Grid (Span 9) -->
<section class="col-span-12 xl:col-span-9 flex flex-col gap-sm">
<header class="flex justify-between items-center pb-base border-b border-outline-variant">
<h2 class="font-label-caps text-label-caps text-on-surface">Active Deployments</h2>
<span class="font-code-sm text-code-sm text-on-surface-variant">4 / 5 Nodes Running</span>
</header>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-md">
<!-- Card 1: Active -->
<div class="bg-surface-container-low border-l-[3px] border-l-secondary border-y border-r border-outline-variant rounded-DEFAULT p-md flex flex-col gap-sm relative group hover:bg-surface-container transition-colors">
<div class="flex justify-between items-start">
<div>
<h3 class="font-body-md text-body-md font-bold text-on-surface">API Gateway</h3>
<p class="font-code-sm text-code-sm text-outline">Namespace: core-infra</p>
</div>
<div class="bg-secondary/10 text-secondary border border-secondary/20 px-xs py-[2px] rounded font-label-caps text-[9px] flex items-center gap-[4px]">
<span class="w-[4px] h-[4px] rounded-full bg-secondary"></span> UP
                                </div>
</div>
<!-- Sparkline (Mock) -->
<div class="h-8 w-full mt-xs opacity-80">
<svg class="w-full h-full stroke-secondary glow-emerald" fill="none" preserveaspectratio="none" stroke-width="1.5" viewbox="0 0 100 20">
<path d="M0,15 L10,12 L20,18 L30,5 L40,8 L50,15 L60,2 L70,10 L80,16 L90,4 L100,8"></path>
</svg>
</div>
<div class="flex items-center gap-xs mt-auto pt-sm border-t border-outline-variant/50">
<button class="p-[4px] rounded text-on-surface-variant hover:text-secondary hover:bg-surface-variant/50 transition-colors" title="Restart">
<span class="material-symbols-outlined text-[16px]" data-icon="refresh">refresh</span>
</button>
<button class="p-[4px] rounded text-on-surface-variant hover:text-error hover:bg-surface-variant/50 transition-colors" title="Stop">
<span class="material-symbols-outlined text-[16px]" data-icon="stop">stop</span>
</button>
<div class="flex-1"></div>
<button class="px-sm py-[2px] rounded border border-outline-variant text-on-surface-variant font-label-caps text-[10px] hover:border-primary hover:text-primary transition-colors flex items-center gap-xs">
<span class="material-symbols-outlined text-[12px]" data-icon="terminal">terminal</span> Shell
                                </button>
</div>
</div>
<!-- Card 2: Active (Neutral/Processing) -->
<div class="bg-surface-container-low border-l-[3px] border-l-primary border-y border-r border-outline-variant rounded-DEFAULT p-md flex flex-col gap-sm relative group hover:bg-surface-container transition-colors shadow-[0_0_15px_rgba(76,215,246,0.05)]">
<div class="flex justify-between items-start">
<div>
<h3 class="font-body-md text-body-md font-bold text-on-surface">User Auth Services</h3>
<p class="font-code-sm text-code-sm text-outline">Namespace: identity</p>
</div>
<div class="bg-primary/10 text-primary border border-primary/20 px-xs py-[2px] rounded font-label-caps text-[9px] flex items-center gap-[4px]">
<span class="w-[4px] h-[4px] rounded-full bg-primary status-dot-pulse"></span> SYNCING
                                </div>
</div>
<div class="h-8 w-full mt-xs opacity-80">
<svg class="w-full h-full stroke-primary glow-cyan" fill="none" preserveaspectratio="none" stroke-width="1.5" viewbox="0 0 100 20">
<path d="M0,10 L20,12 L40,8 L60,15 L80,5 L100,10"></path>
</svg>
</div>
<div class="flex items-center gap-xs mt-auto pt-sm border-t border-outline-variant/50">
<button class="p-[4px] rounded text-on-surface-variant hover:text-secondary hover:bg-surface-variant/50 transition-colors" title="Restart">
<span class="material-symbols-outlined text-[16px]" data-icon="refresh">refresh</span>
</button>
<button class="p-[4px] rounded text-on-surface-variant hover:text-error hover:bg-surface-variant/50 transition-colors" title="Stop">
<span class="material-symbols-outlined text-[16px]" data-icon="stop">stop</span>
</button>
<div class="flex-1"></div>
<button class="px-sm py-[2px] rounded border border-outline-variant text-on-surface-variant font-label-caps text-[10px] hover:border-primary hover:text-primary transition-colors flex items-center gap-xs">
<span class="material-symbols-outlined text-[12px]" data-icon="terminal">terminal</span> Shell
                                </button>
</div>
</div>
<!-- Card 3: Down/Error -->
<div class="bg-surface-container-lowest border-l-[3px] border-l-error border-y border-r border-error/30 rounded-DEFAULT p-md flex flex-col gap-sm relative group hover:bg-surface-container transition-colors">
<div class="flex justify-between items-start">
<div>
<h3 class="font-body-md text-body-md font-bold text-on-surface">Data Pipeline Worker</h3>
<p class="font-code-sm text-code-sm text-outline">Namespace: analytics</p>
</div>
<div class="bg-error/10 text-error border border-error/20 px-xs py-[2px] rounded font-label-caps text-[9px] flex items-center gap-[4px]">
<span class="w-[4px] h-[4px] rounded-full bg-error"></span> CRASHBACKOFF
                                </div>
</div>
<div class="h-8 w-full mt-xs opacity-50">
<svg class="w-full h-full stroke-error" fill="none" preserveaspectratio="none" stroke-dasharray="2 2" stroke-width="1.5" viewbox="0 0 100 20">
<path d="M0,18 L40,18 L45,2 L55,18 L100,18"></path>
</svg>
</div>
<div class="flex items-center gap-xs mt-auto pt-sm border-t border-outline-variant/50">
<button class="p-[4px] rounded text-on-surface-variant hover:text-secondary hover:bg-surface-variant/50 transition-colors" title="Start">
<span class="material-symbols-outlined text-[16px]" data-icon="play_arrow">play_arrow</span>
</button>
<button class="p-[4px] rounded text-error/50 cursor-not-allowed" title="Stop">
<span class="material-symbols-outlined text-[16px]" data-icon="stop">stop</span>
</button>
<div class="flex-1"></div>
<button class="px-sm py-[2px] rounded border border-error/50 text-error font-label-caps text-[10px] hover:border-error hover:bg-error/10 transition-colors flex items-center gap-xs">
<span class="material-symbols-outlined text-[12px]" data-icon="description">description</span> Logs
                                </button>
</div>
</div>
</div>
</section>
<!-- Quick Launch Sidebar (Span 3) -->
<aside class="col-span-12 xl:col-span-3 flex flex-col gap-sm">
<header class="flex justify-between items-center pb-base border-b border-outline-variant">
<h2 class="font-label-caps text-label-caps text-on-surface">Quick Deploy</h2>
</header>
<div class="bg-surface-container-low border border-outline-variant rounded-DEFAULT p-md flex flex-col gap-md">
<div class="flex flex-col gap-xs">
<label class="font-code-sm text-code-sm text-on-surface-variant">Manifest URI or Image</label>
<input class="w-full bg-surface-container-lowest border border-outline-variant rounded py-[6px] px-sm font-code-sm text-code-sm text-on-surface focus:outline-none focus:border-primary focus:ring-1 focus:ring-primary transition-colors" type="text" value="docker.io/nginx:latest"/>
</div>
<div class="flex gap-sm">
<select class="bg-surface-container-lowest border border-outline-variant rounded py-[6px] px-sm font-code-sm text-code-sm text-on-surface focus:outline-none flex-1 appearance-none">
<option>default</option>
<option>core-infra</option>
<option>testing</option>
</select>
<button class="bg-surface-variant hover:bg-primary/20 text-on-surface hover:text-primary border border-outline-variant hover:border-primary px-md py-[6px] rounded font-label-caps text-label-caps transition-colors flex items-center gap-xs">
                                Apply <span class="material-symbols-outlined text-[14px]" data-icon="send">send</span>
</button>
</div>
</div>
</aside>
</div>
<!-- Bottom Section: Terminal Logs -->
<section class="flex-1 flex flex-col min-h-[300px] border border-outline-variant rounded-lg overflow-hidden bg-[#000000] shadow-[inset_0_0_20px_rgba(0,0,0,0.8)] relative">
<!-- Terminal Header -->
<header class="bg-surface-container-highest border-b border-outline-variant px-md py-xs flex justify-between items-center shrink-0">
<div class="flex items-center gap-sm">
<span class="material-symbols-outlined text-[16px] text-on-surface-variant" data-icon="terminal">terminal</span>
<h2 class="font-label-caps text-label-caps text-on-surface">Cluster Event Log</h2>
</div>
<div class="flex items-center gap-md font-code-sm text-[10px] text-on-surface-variant">
<span class="flex items-center gap-xs"><span class="w-[6px] h-[6px] rounded-full bg-secondary"></span> Live Tailing</span>
<button class="hover:text-primary transition-colors">Clear</button>
</div>
</header>
<!-- Terminal Body -->
<div class="flex-1 overflow-y-auto p-md font-code-sm text-code-sm leading-relaxed" id="terminal-output">
<div class="text-on-surface-variant/50">[2023-10-27T14:32:01Z] SYSTEM: LaraKube CLI initialized. Context: k3d-dev-cluster</div>
<div class="text-secondary">[2023-10-27T14:32:05Z] INFO: Checking node health... 4/5 nodes reporting Ready state.</div>
<div class="text-on-surface">[2023-10-27T14:32:10Z] DEPLOY: Reconciling Deployment identity/user-auth-services</div>
<div class="text-primary">[2023-10-27T14:32:11Z] SYNC: Container image pull started for user-auth-services:v1.4.2</div>
<div class="text-on-surface">[2023-10-27T14:32:15Z] ROUTING: Traefik ingress updated for host auth.local.dev</div>
<div class="text-error">[2023-10-27T14:32:20Z] WARN: analytics/data-pipeline-worker pod restarting (CrashLoopBackOff). Exit code 137.</div>
<div class="text-on-surface-variant/50">[2023-10-27T14:32:21Z] OOMKilled: Container memory limit exceeded. Requesting telemetry dump...</div>
<div class="text-secondary">[2023-10-27T14:32:30Z] INFO: Network mesh synchronized.</div>
<div class="flex items-center mt-sm">
<span class="text-primary mr-sm">root@larakube:~$</span>
<span class="w-[8px] h-[16px] bg-primary animate-[pulse_1s_step-end_infinite]"></span>
</div>
</div>
</section>
</div>
</main>
<script>
        // Simple script to auto-scroll terminal if needed for visual effect
        const terminal = document.getElementById('terminal-output');
        terminal.scrollTop = terminal.scrollHeight;
    </script>
</body></html>