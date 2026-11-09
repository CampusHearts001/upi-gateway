@php
    $overview = $overview ?? [];
    $summary = $summary ?? ($overview['summary'] ?? [
        'total' => 0, 'passed' => 0, 'failed' => 0, 'warnings' => 0, 'optional' => 0, 'ready' => false,
    ]);
    $groups = $groups ?? ($overview['groups'] ?? []);
    $allPassed = $allPassed ?? ($overview['allPassed'] ?? ($summary['ready'] ?? false));
    $nativeInstaller = $nativeInstaller ?? false;
    $nextUrl = $nextUrl ?? ($nativeInstaller ? ($installerUrl ?? 'install.php').'?step=database' : route('install.database'));
    $recheckUrl = $recheckUrl ?? ($nativeInstaller ? ($installerUrl ?? 'install.php').'?step=deployment-check' : (Route::has('install.recheck') ? route('install.recheck') : 'install/recheck'));
    $csrfToken = $csrfToken ?? csrf_token();
@endphp

<style nonce="{{ $csp_nonce ?? '' }}">
    .req-container { width: 100%; margin: 1.5rem auto 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; text-align: left; }
    .req-summary-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.25rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05); }
    .req-banner { display: flex; align-items: flex-start; gap: 0.85rem; padding: 0.85rem 1rem; border-radius: 10px; margin-bottom: 1.15rem; font-size: 0.88rem; line-height: 1.5; }
    .req-banner.ready { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
    .req-banner.unready { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    .req-banner-title { font-weight: 700; font-size: 0.95rem; margin-bottom: 0.15rem; }
    .req-stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem; margin-top: 0.75rem; }
    .req-stat-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.75rem 0.65rem; text-align: center; }
    .req-stat-number { font-size: 1.35rem; font-weight: 750; color: #0f172a; line-height: 1.2; }
    .req-stat-label { font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b; margin-top: 0.25rem; }
    .req-stat-box.stat-pass .req-stat-number { color: #059669; }
    .req-stat-box.stat-fail .req-stat-number { color: #dc2626; }
    .req-stat-box.stat-warn .req-stat-number { color: #d97706; }

    .req-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.75rem; }
    .req-section-headline { font-size: 0.95rem; font-weight: 700; color: #1e293b; letter-spacing: -0.01em; margin: 0; }
    .btn-recheck { display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.5rem 1rem; border: 1px solid #cbd5e1; background: #ffffff; color: #334155; border-radius: 8px; font-size: 0.82rem; font-weight: 600; cursor: pointer; transition: all 0.15s ease; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
    .btn-recheck:hover { background: #f8fafc; border-color: #94a3b8; color: #0f172a; }
    .btn-recheck.is-loading svg { animation: req-spin 0.8s linear infinite; }

    .req-group { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 1.15rem; overflow: hidden; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03); }
    .req-group-header { padding: 0.85rem 1.15rem; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
    .req-group-title { font-size: 0.88rem; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
    .req-group-desc { font-size: 0.75rem; color: #64748b; margin-top: 0.15rem; }
    .req-group-badge { font-size: 0.72rem; font-weight: 600; padding: 0.15rem 0.55rem; border-radius: 9999px; }
    .req-group-badge.all-ok { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
    .req-group-badge.has-issues { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

    .req-list { list-style: none; margin: 0; padding: 0; }
    .req-item { padding: 0.85rem 1.15rem; border-bottom: 1px solid #f1f5f9; transition: background 0.15s ease; }
    .req-item:last-child { border-bottom: none; }
    .req-item:hover { background: #fafafa; }
    .req-item-main { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; }
    .req-item-left { display: flex; align-items: center; gap: 0.6rem; flex: 1; min-width: 200px; }
    .req-item-name { font-size: 0.85rem; font-weight: 600; color: #0f172a; }
    .req-item-value { font-size: 0.75rem; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; background: #f1f5f9; color: #475569; padding: 0.15rem 0.45rem; border-radius: 4px; border: 1px solid #e2e8f0; }

    .req-pill { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.03em; padding: 0.2rem 0.6rem; border-radius: 6px; display: inline-flex; align-items: center; gap: 0.3rem; border: 1px solid transparent; text-transform: uppercase; }
    .req-pill.pill-pass { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
    .req-pill.pill-missing { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
    .req-pill.pill-disabled { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
    .req-pill.pill-failed { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
    .req-pill.pill-warning { background: #fffbeb; color: #92400e; border-color: #fde68a; }
    .req-pill.pill-optional { background: #f8fafc; color: #64748b; border-color: #e2e8f0; }

    .req-remediation-box { margin-top: 0.65rem; background: #fff5f5; border: 1px solid #fed7d7; border-left: 3px solid #e53e3e; border-radius: 6px; padding: 0.65rem 0.85rem; font-size: 0.78rem; line-height: 1.5; color: #2d3748; }
    .req-remediation-box.is-warning { background: #fffdf5; border-color: #feebc8; border-left-color: #dd6b20; color: #744210; }
    .req-remediation-title { font-weight: 700; margin-bottom: 0.2rem; display: flex; align-items: center; gap: 0.35rem; }
    .req-remediation-guide { color: #4a5568; margin-top: 0.25rem; }
    .req-remediation-guide code { background: rgba(0, 0, 0, 0.05); padding: 0.1rem 0.3rem; border-radius: 4px; font-size: 0.75rem; font-family: monospace; }

    .req-action-footer { margin-top: 1.75rem; border-top: 1px solid #e2e8f0; padding-top: 1.5rem; text-align: center; }
    .req-btn-next { display: block; width: 100%; padding: 0.85rem 1.25rem; font-size: 0.92rem; font-weight: 700; color: #ffffff; background: #4f46e5; border: 1px solid #4f46e5; border-radius: 10px; text-decoration: none; text-align: center; box-shadow: 0 4px 14px rgba(79, 70, 229, 0.2); transition: all 0.15s ease; cursor: pointer; }
    .req-btn-next:hover { background: #4338ca; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(79, 70, 229, 0.28); }
    .req-btn-next[disabled], .req-btn-next.is-disabled { background: #94a3b8; border-color: #94a3b8; cursor: not-allowed; transform: none; box-shadow: none; opacity: 0.7; }

    @keyframes req-spin { to { transform: rotate(360deg); } }
    @media (max-width: 640px) {
        .req-stats-grid { grid-template-columns: repeat(2, 1fr); }
        .req-item-main { flex-direction: column; align-items: flex-start; }
    }
</style>

<div class="req-container" data-requirements-panel>
    {{-- Summary Card --}}
    <div class="req-summary-card">
        <div class="req-banner {{ $summary['ready'] ? 'ready' : 'unready' }}" data-readiness-banner>
            @if ($summary['ready'])
                <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink:0; margin-top:1px;"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" /></svg>
                <div>
                    <div class="req-banner-title">System Ready for Installation</div>
                    <div>All mandatory prerequisites are met. You can safely proceed with database configuration.</div>
                </div>
            @else
                <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink:0; margin-top:1px;"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                <div>
                    <div class="req-banner-title">Requirements Incomplete</div>
                    <div>{{ $summary['failed'] }} mandatory requirement(s) must be resolved before installation can continue.</div>
                </div>
            @endif
        </div>

        <div class="req-stats-grid">
            <div class="req-stat-box">
                <div class="req-stat-number" data-stat="total">{{ $summary['total'] }}</div>
                <div class="req-stat-label">Total Checks</div>
            </div>
            <div class="req-stat-box stat-pass">
                <div class="req-stat-number" data-stat="passed">{{ $summary['passed'] }}</div>
                <div class="req-stat-label">Passed</div>
            </div>
            <div class="req-stat-box stat-fail">
                <div class="req-stat-number" data-stat="failed">{{ $summary['failed'] }}</div>
                <div class="req-stat-label">Failed</div>
            </div>
            <div class="req-stat-box stat-warn">
                <div class="req-stat-number" data-stat="warnings">{{ $summary['warnings'] }}</div>
                <div class="req-stat-label">Warnings</div>
            </div>
        </div>
    </div>

    {{-- Toolbar --}}
    <div class="req-toolbar">
        <h3 class="req-section-headline">Detailed Environment &amp; Dependency Checks</h3>
        <button type="button" class="btn-recheck" data-recheck-button>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg>
            <span>Recheck Requirements</span>
        </button>
    </div>

    {{-- Grouped Requirement Sections --}}
    <div class="req-groups-container" data-groups-container>
        @foreach ($groups as $groupKey => $group)
            @php
                $groupFailed = count(array_filter($group['checks'], fn($c) => ! $c['pass'] && ($c['required'] ?? true)));
                $groupWarn = count(array_filter($group['checks'], fn($c) => ! $c['pass'] && ! ($c['required'] ?? true)));
            @endphp
            <div class="req-group" data-group="{{ $groupKey }}">
                <div class="req-group-header">
                    <div>
                        <div class="req-group-title">
                            <span>{{ $group['title'] }}</span>
                        </div>
                        <div class="req-group-desc">{{ $group['description'] }}</div>
                    </div>
                    <div>
                        @if ($groupFailed > 0)
                            <span class="req-group-badge has-issues">{{ $groupFailed }} issue(s)</span>
                        @elseif ($groupWarn > 0)
                            <span class="req-group-badge" style="background:#fffbeb; color:#b45309; border:1px solid #fde68a;">{{ $groupWarn }} advisory</span>
                        @else
                            <span class="req-group-badge all-ok">All Passed</span>
                        @endif
                    </div>
                </div>

                <ul class="req-list">
                    @foreach ($group['checks'] as $check)
                        @php
                            $status = strtoupper($check['status'] ?? ($check['pass'] ? 'PASS' : 'FAILED'));
                            $pillClass = match ($status) {
                                'PASS' => 'pill-pass',
                                'MISSING' => 'pill-missing',
                                'DISABLED' => 'pill-disabled',
                                'FAILED' => 'pill-failed',
                                'WARNING' => 'pill-warning',
                                'OPTIONAL' => 'pill-optional',
                                default => $check['pass'] ? 'pill-pass' : 'pill-failed',
                            };
                        @endphp
                        <li class="req-item" data-check-name="{{ $check['name'] }}">
                            <div class="req-item-main">
                                <div class="req-item-left">
                                    <span class="req-item-name">{{ $check['name'] }}</span>
                                    @if (! empty($check['current_value']))
                                        <span class="req-item-value">{{ $check['current_value'] }}</span>
                                    @endif
                                </div>
                                <div>
                                    <span class="req-pill {{ $pillClass }}">{{ $status }}</span>
                                </div>
                            </div>

                            @if (! $check['pass'])
                                <div class="req-remediation-box {{ ! empty($check['required']) ? '' : 'is-warning' }}">
                                    <div class="req-remediation-title">
                                        <strong>Why it's needed:</strong> {{ $check['description'] }}
                                    </div>
                                    <div class="req-remediation-guide">
                                        <strong>How to fix:</strong> {{ $check['remediation'] }}
                                    </div>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>

    {{-- Action Footer --}}
    <div class="req-action-footer">
        @if ($allPassed)
            <a hidden data-deployment-continue aria-disabled="true" data-deployment-href="{{ $nextUrl }}" class="req-btn-next">
                Next: Database Setup &rarr;
            </a>
        @else
            <button type="button" class="req-btn-next is-disabled" disabled title="Fix mandatory requirements above to proceed">
                Resolve all failed requirements before proceeding.
            </button>
        @endif
    </div>
</div>

<script nonce="{{ $csp_nonce ?? '' }}">
document.addEventListener('DOMContentLoaded', () => {
    const recheckBtn = document.querySelector('[data-recheck-button]');
    const hostingRecheck = document.querySelector('[data-hosting-recheck]');
    const recheckUrl = @json($recheckUrl);
    const csrfToken = @json($csrfToken);
    const isNative = @json($nativeInstaller);

    if (recheckBtn) {
        recheckBtn.addEventListener('click', async () => {
            recheckBtn.classList.add('is-loading');
            recheckBtn.disabled = true;
            const originalText = recheckBtn.querySelector('span')?.textContent || 'Recheck';
            if (recheckBtn.querySelector('span')) recheckBtn.querySelector('span').textContent = 'Checking...';

            if (hostingRecheck) {
                try { hostingRecheck.click(); } catch (_) {}
            }

            try {
                const response = await fetch(recheckUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: new URLSearchParams({
                        '_token': csrfToken,
                        'recheck': '1'
                    })
                });

                if (response.ok) {
                    const data = await response.json();
                    if (data.summary) {
                        document.querySelector('[data-stat="total"]').textContent = data.summary.total;
                        document.querySelector('[data-stat="passed"]').textContent = data.summary.passed;
                        document.querySelector('[data-stat="failed"]').textContent = data.summary.failed;
                        document.querySelector('[data-stat="warnings"]').textContent = data.summary.warnings;

                        const banner = document.querySelector('[data-readiness-banner]');
                        if (banner) {
                            if (data.summary.ready) {
                                banner.className = 'req-banner ready';
                                banner.innerHTML = `
                                    <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink:0; margin-top:1px;"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" /></svg>
                                    <div>
                                        <div class="req-banner-title">System Ready for Installation</div>
                                        <div>All mandatory prerequisites are met. You can safely proceed with database configuration.</div>
                                    </div>
                                `;
                            } else {
                                banner.className = 'req-banner unready';
                                banner.innerHTML = `
                                    <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink:0; margin-top:1px;"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                                    <div>
                                        <div class="req-banner-title">Requirements Incomplete</div>
                                        <div>${data.summary.failed} mandatory requirement(s) must be resolved before installation can continue.</div>
                                    </div>
                                `;
                            }
                        }
                    }
                }
                // Gracefully reload to render fresh server-side Blade state
                window.location.reload();
            } catch (_) {
                window.location.reload();
            } finally {
                recheckBtn.classList.remove('is-loading');
                recheckBtn.disabled = false;
                if (recheckBtn.querySelector('span')) recheckBtn.querySelector('span').textContent = originalText;
            }
        });
    }
});
</script>
