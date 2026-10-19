/* پست‌ساز آهنگ‌بان — رفتار پنل (بدون وابستگی) */
(function () {
    'use strict';

    /* ---------- تب‌ها (با hash آدرس) ---------- */
    var tabs = document.querySelectorAll('.ps-tab');
    var panels = document.querySelectorAll('.ps-panel');

    function activateTab(name) {
        var found = false;
        panels.forEach(function (panel) {
            if (panel.getAttribute('data-panel') === name) {
                found = true;
            }
        });
        if (!found) {
            name = 'single';
        }
        tabs.forEach(function (tab) {
            tab.classList.toggle('is-active', tab.getAttribute('data-tab') === name);
        });
        panels.forEach(function (panel) {
            panel.classList.toggle('is-active', panel.getAttribute('data-panel') === name);
        });
    }

    if (tabs.length) {
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function (event) {
                event.preventDefault();
                var name = tab.getAttribute('data-tab');
                activateTab(name);
                if (history.replaceState) {
                    history.replaceState(null, '', '#' + name);
                } else {
                    location.hash = name;
                }
            });
        });
        activateTab((location.hash || '#single').replace('#', ''));
        window.addEventListener('hashchange', function () {
            activateTab((location.hash || '#single').replace('#', ''));
        });
    }

    /* ---------- نمایش نام فایل انتخاب‌شده ---------- */
    document.querySelectorAll('.ps-media').forEach(function (box) {
        var input = box.querySelector('input[type="file"]');
        var label = box.querySelector('.ps-file-name');
        var urlInput = box.querySelector('.ps-media-url');
        if (!input) {
            return;
        }
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (file) {
                var size = file.size > 1048576 ? (file.size / 1048576).toFixed(1) + ' MB' : Math.round(file.size / 1024) + ' KB';
                label.textContent = file.name + ' (' + size + ')';
                box.classList.add('has-file');
                if (urlInput) {
                    urlInput.placeholder = 'فایل آپلودی استفاده می‌شود';
                }
            } else {
                label.textContent = '';
                box.classList.remove('has-file');
                if (urlInput) {
                    urlInput.placeholder = 'https://...';
                }
            }
        });
    });

    /* ---------- دسته‌های پیش‌فرض با تغییر نوع اثر ---------- */
    document.querySelectorAll('form[data-defaults]').forEach(function (form) {
        var select = form.querySelector('[data-type-select]');
        if (!select) {
            return;
        }
        var defaults;
        try {
            defaults = JSON.parse(form.getAttribute('data-defaults') || '{}');
        } catch (e) {
            defaults = {};
        }
        select.addEventListener('change', function () {
            var ids = defaults[select.value] || [];
            form.querySelectorAll('input[name="categories[]"]').forEach(function (box) {
                box.checked = ids.indexOf(parseInt(box.value, 10)) !== -1;
            });
        });
    });

    /* ---------- ردیف‌های قطعه‌های آلبوم ---------- */
    document.querySelectorAll('.ps-tracks').forEach(function (block) {
        var form = block.closest('form');
        var body = block.querySelector('.ps-tracks-body');
        var addButton = block.querySelector('[data-add-track]');
        var max = parseInt(form && form.getAttribute('data-max-tracks'), 10) || 30;

        function renumber() {
            var rows = body.querySelectorAll('.ps-track');
            rows.forEach(function (row, index) {
                row.querySelector('.ps-track-no').textContent = index + 1;
            });
            if (addButton) {
                addButton.disabled = rows.length >= max;
            }
        }

        function bindRow(row) {
            var remove = row.querySelector('.ps-track-remove');
            if (remove) {
                remove.addEventListener('click', function () {
                    if (body.querySelectorAll('.ps-track').length > 1) {
                        row.parentNode.removeChild(row);
                    } else {
                        row.querySelectorAll('input').forEach(function (input) {
                            input.value = '';
                        });
                    }
                    renumber();
                });
            }
            // با Enter در لینک 128 آخرین ردیف، ردیف جدید اضافه می‌شود
            var last = row.querySelector('input[name="track_128[]"]');
            if (last) {
                last.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        if (row === body.lastElementChild) {
                            addRow();
                        }
                        var next = row.nextElementSibling;
                        if (next) {
                            next.querySelector('input').focus();
                        }
                    }
                });
            }
        }

        function addRow() {
            var rows = body.querySelectorAll('.ps-track');
            if (rows.length >= max) {
                return null;
            }
            var template = rows[rows.length - 1];
            var row = template.cloneNode(true);
            row.querySelectorAll('input').forEach(function (input) {
                input.value = '';
            });
            body.appendChild(row);
            bindRow(row);
            renumber();
            return row;
        }

        body.querySelectorAll('.ps-track').forEach(bindRow);
        if (addButton) {
            addButton.addEventListener('click', function () {
                var row = addRow();
                if (row) {
                    row.querySelector('input').focus();
                }
            });
        }
        renumber();

        // چسباندن چند خط «نام | لینک 320 | لینک 128» در فیلد نام فارسی اولین ردیف خالی → پر شدن ردیف‌ها
        body.addEventListener('paste', function (event) {
            var target = event.target;
            if (!target || target.name !== 'track_fa[]') {
                return;
            }
            var text = (event.clipboardData || window.clipboardData).getData('text') || '';
            var lines = text.split(/\r?\n/).filter(function (line) {
                return line.trim() !== '';
            });
            if (lines.length < 2 || text.indexOf('|') === -1) {
                return;
            }
            event.preventDefault();
            var row = target.closest('.ps-track');
            lines.forEach(function (line, index) {
                if (index > 0) {
                    row = row.nextElementSibling || addRow();
                    if (!row) {
                        return;
                    }
                }
                var parts = line.split('|').map(function (part) {
                    return part.trim();
                });
                var inputs = row.querySelectorAll('input');
                inputs[0].value = parts[0] || '';
                inputs[1].value = parts[1] && parts[1].indexOf('http') !== 0 ? parts[1] : (inputs[1].value || '');
                var links = parts.filter(function (part) {
                    return part.indexOf('http') === 0;
                });
                inputs[2].value = links[0] || '';
                inputs[3].value = links[1] || '';
            });
            renumber();
        });
    });

    /* ---------- قفل دکمه هنگام ارسال ---------- */
    document.querySelectorAll('form.ps-form').forEach(function (form) {
        form.addEventListener('submit', function () {
            var button = form.querySelector('button[type="submit"]');
            var progress = form.querySelector('.ps-progress');
            if (button) {
                button.disabled = true;
                button.textContent = 'در حال ارسال…';
            }
            if (progress) {
                progress.hidden = false;
            }
        });
    });

    /* ---------- کپی لینک ---------- */
    document.querySelectorAll('[data-copy]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = button.parentNode.querySelector('input');
            if (!input) {
                return;
            }
            input.select();
            var done = false;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(input.value).then(function () {
                    button.textContent = 'کپی شد';
                });
                done = true;
            }
            if (!done) {
                try {
                    document.execCommand('copy');
                    button.textContent = 'کپی شد';
                } catch (e) {
                    /* ignore */
                }
            }
            setTimeout(function () {
                button.textContent = 'کپی';
            }, 1500);
        });
    });
})();
