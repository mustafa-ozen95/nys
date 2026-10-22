import { $, $$ } from '../lib/dom.js';

/**
 * Sütunlar arasında klavyeyle gezinme.
 *
 * Bu tasarımın çekirdeği klavye gezinmesidir: ↑↓ satırlar arasında dolaşır,
 * → veya Enter seçili satırı açar, ← bir önceki sütuna döner, "/" filtreye
 * odaklanır.
 *
 * Yalnızca odak ve tıklama yönetir; hangi satırın nereye gittiğine sunucunun
 * ürettiği bağlantı karar verir.
 */

const ROW_SELECTOR = '.row-item, a.switch-row, button.port-row, .grid-row';

function columns() {
    return $$('.columns .column, .columns .detail');
}

function rows(column) {
    return $$(ROW_SELECTOR, column).filter((row) => row.offsetParent !== null);
}

function activeColumn() {
    const focus = document.activeElement?.closest('.column, .detail');

    return focus ?? columns().at(-1) ?? null;
}

function migrate(column, direction) {
    const list = rows(column);

    if (list.length === 0) {
        return;
    }

    const current = list.indexOf(document.activeElement);
    const next = current === -1
        ? 0
        : Math.min(list.length - 1, Math.max(0, current + direction));

    list[next].focus();
    list[next].scrollIntoView({ block: 'nearest' });
}

function neighborColumn(column, direction) {
    const list = columns();
    const next = list[list.indexOf(column) + direction];

    if (!next) {
        return;
    }

    const target = rows(next);
    (target.find((row) => row.getAttribute('aria-selected') === 'true') ?? target[0])?.focus();
    next.scrollIntoView({ inline: 'nearest', behavior: 'smooth' });
}

function isWriteField(target) {
    return ['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName);
}

/** document dinleyicisi bir kez kurulur; init her sayfa geçişinde koşuyor. */
let listening = false;

export function init() {
    if (! $('.columns')) {
        return;
    }

    // Satırlar klavyeyle odaklanabilir olmalı; bağlantı ve düğme zaten öyledir.
    $$(ROW_SELECTOR).forEach((row) => {
        if (! row.hasAttribute('tabindex') && ! ['A', 'BUTTON'].includes(row.tagName)) {
            row.tabIndex = 0;
        }
    });

    if (listening) {
        return;
    }

    listening = true;

    document.addEventListener('keydown', (event) => {
        if (event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }

        if (event.key === '/' && ! isWriteField(event.target)) {
            event.preventDefault();
            $('.filter__input')?.focus();

            return;
        }

        if (isWriteField(event.target)) {
            return;
        }

        const column = activeColumn();

        if (! column) {
            return;
        }

        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                migrate(column, 1);
                break;
            case 'ArrowUp':
                event.preventDefault();
                migrate(column, -1);
                break;
            case 'ArrowRight':
                event.preventDefault();
                document.activeElement?.matches(ROW_SELECTOR)
                    ? document.activeElement.click()
                    : neighborColumn(column, 1);
                break;
            case 'ArrowLeft':
                event.preventDefault();
                neighborColumn(column, -1);
                break;
            default:
        }
    });
}
