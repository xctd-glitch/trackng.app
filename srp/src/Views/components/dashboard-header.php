<header class="sticky top-0 z-50 w-full border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60">
    <div class="mx-auto flex h-14 max-w-4xl items-center px-4 sm:px-5">
        <a href="/" class="flex items-center gap-2 sm:gap-3" aria-label="Back to dashboard overview">
            <img src="/assets/icons/fox.svg" alt="Fox head logo" class="h-8 w-8" width="32" height="32">
            <div class="leading-tight">
                <div class="text-sm font-semibold tracking-tight">SRP Smart Redirect Platform</div>
                <div class="hidden text-[11px] text-muted-foreground sm:block">No "smart" buzzword without actual routing logic.</div>
            </div>
        </a>

        <div class="ml-auto flex items-center gap-2 sm:gap-3">
            <div class="flex items-center gap-2 rounded-md border px-2.5 py-1 text-[11px] font-medium transition-colors duration-200"
                 :class="cfg.system_on
                    ? (muteStatus.isMuted
                        ? 'bg-amber-500 text-white shadow-sm border-amber-500'
                        : 'bg-primary text-primary-foreground shadow-sm border-primary')
                    : 'bg-secondary text-muted-foreground'">
                <span class="h-1.5 w-1.5 rounded-full"
                      :class="cfg.system_on
                        ? (muteStatus.isMuted ? 'bg-white animate-pulse' : 'bg-emerald-500 animate-pulse')
                        : 'bg-gray-400'"></span>
                <span x-text="cfg.system_on ? (muteStatus.isMuted ? 'Muted' : 'Active') : 'Offline'"></span>
            </div>

            <button type="button"
                    @click="toggleAutoRefresh()"
                    class="btn btn-ghost btn-icon"
                    :title="autoRefreshEnabled ? 'Pause auto-refresh' : 'Resume auto-refresh'"
                    aria-label="Toggle auto-refresh">
                <svg x-show="autoRefreshEnabled" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <svg x-show="!autoRefreshEnabled" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </button>

            <form method="post" action="/logout.php" class="flex">
                <input type="hidden" name="_csrf_token"
                       value="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'); ?>">
                <button type="submit" class="btn btn-secondary btn-sm flex items-center gap-1" aria-label="Logout from SRP">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v1" />
                    </svg>
                    <span class="hidden sm:inline">Logout</span>
                </button>
            </form>
        </div>
    </div>
</header>
