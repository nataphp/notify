{* hx-headers is inherited by the hx-post buttons below: the controller enforces CSRF. *}
<div class="w-80 max-w-[calc(100vw-2rem)]" data-unread-count="{$unread_count|default:0}" hx-headers='{literal}{"X-CSRF-Token": "{/literal}{$notify_csrf_token|default:''|escape}{literal}"}{/literal}'>

    {* ── Header ── *}
    <div class="flex items-center justify-between px-4 pt-4 pb-3">
        <div class="flex items-center gap-2">
            <div class="w-7 h-7 rounded-xl flex items-center justify-center shrink-0" style="background: linear-gradient(135deg, #4f46e5, #3b82f6);">
                <i class="fa-regular fa-bell text-white text-xs"></i>
            </div>
            <span class="text-sm font-bold text-gray-800">{t domain='notify' msg='Notifications'}</span>
        </div>
        {if $unread_count > 0}
            <span class="inline-flex items-center gap-1 text-[10px] font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded-full">
                <span class="w-1.5 h-1.5 bg-indigo-500 rounded-full animate-pulse"></span>
                {$unread_count} {t domain='notify' msg='unread'}
            </span>
        {else}
            <span class="text-[10px] font-semibold text-gray-400 uppercase tracking-widest">{t domain='notify' msg='All caught up'}</span>
        {/if}
    </div>

    <div class="h-px bg-gray-100 mx-3"></div>

    {* ── Notification list ── *}
    <div class="max-h-80 overflow-auto">
        {if $notifications->isEmpty()}
            <div class="flex flex-col items-center justify-center py-8 px-4 gap-2">
                <div class="w-10 h-10 rounded-2xl bg-gray-50 flex items-center justify-center">
                    <i class="fa-regular fa-bell-slash text-gray-300 text-lg"></i>
                </div>
                <p class="text-xs font-medium text-gray-400">{t domain='notify' msg='No notifications yet.'}</p>
            </div>
        {else}
            {foreach from=$notifications item=n}
                <div class="group relative flex items-start gap-3 px-4 py-3 transition-colors hover:bg-gray-50/80 {if !$n->read_at}bg-indigo-50/40{/if}">
                    {* Unread indicator dot *}
                    {if !$n->read_at}
                        <span class="absolute left-1.5 top-4 w-1.5 h-1.5 rounded-full bg-indigo-500 shrink-0"></span>
                    {/if}

                    {* Icon circle *}
                    <div class="w-8 h-8 rounded-xl {if !$n->read_at}bg-indigo-100{else}bg-gray-100{/if} flex items-center justify-center shrink-0 mt-0.5">
                        <i class="fa-regular fa-bell {if !$n->read_at}text-indigo-500{else}text-gray-400{/if} text-xs"></i>
                    </div>

                    {* Content *}
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-bold {if !$n->read_at}text-gray-800{else}text-gray-600{/if} leading-snug">{$n->title|default:''}</p>
                        <p class="text-[11px] {if !$n->read_at}text-gray-600{else}text-gray-400{/if} mt-0.5 leading-snug">{$n->body|default:''}</p>
                        <p class="text-[10px] text-gray-400 mt-1 font-medium">{$n->created|default:''}</p>
                    </div>

                    {* Mark as read button — visible on hover *}
                    {if !$n->read_at}
                        <button
                            class="opacity-0 group-hover:opacity-100 transition-opacity shrink-0 w-6 h-6 rounded-lg bg-white shadow-sm border border-gray-100 flex items-center justify-center mt-0.5"
                            title="{t domain='notify' msg='Mark as read'}"
                            hx-post="/notify/notifications/read/{$n->id}"
                            hx-swap="none"
                            hx-trigger="click"
                            hx-on="htmx:afterRequest: document.body.dispatchEvent(new Event('notify:refresh'))"
                        ><i class="fa-solid fa-check text-indigo-500 text-[9px]"></i></button>
                    {/if}
                </div>
                {if !$n@last}<div class="h-px bg-gray-100/70 mx-4"></div>{/if}
            {/foreach}
        {/if}
    </div>

    <div class="h-px bg-gray-100 mx-3"></div>

    {* ── Footer actions ── *}
    <div class="flex items-center justify-between gap-2 px-3 py-2.5">
        <button
            class="flex items-center gap-1.5 text-xs font-semibold text-indigo-600 hover:text-indigo-700 px-2.5 py-1.5 rounded-xl hover:bg-indigo-50 transition-colors"
            hx-post="/notify/notifications/read-all"
            hx-swap="none"
            hx-on="htmx:afterRequest: document.body.dispatchEvent(new Event('notify:refresh'))"
        >
            <i class="fa-solid fa-check-double text-[10px]"></i>
            {t domain='notify' msg='Mark all as read'}
        </button>
    </div>

    {* ── Enable notifications on your device ── *}
    <div class="{if $push_enabled|default:false}hidden{/if}" data-notify-push-cta>
        <div class="flex items-center justify-between gap-2 px-3 py-2.5">
            <button
                class="flex items-center gap-1.5 text-xs font-medium text-gray-400 hover:text-gray-600 px-2.5 py-1.5 rounded-xl hover:bg-gray-50 transition-colors"
                data-notify-push-enable
                data-vapid-public-key="{$notify_push_vapid_public|default:''}"
                data-csrf-token="{$notify_csrf_token|default:''|escape}">
                <i class="fa-regular fa-bell text-[10px]"></i>
                {t domain='notify' msg='Enable notifications on your device'}
            </button>
        </div>
    </div>
</div>
