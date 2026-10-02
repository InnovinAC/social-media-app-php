/*!
 * phpvin.js: unobtrusive jQuery behaviours for the phpvin framework.
 *
 * Three layers, each usable on its own:
 *
 *   1. Attributes.  data-remote, data-method, data-confirm, data-target,
 *      data-swap. Declarative AJAX with no JavaScript of your own.
 *
 *   2. Behaviours.  phpvin.behavior('name', fn) binds to [data-behavior="name"]
 *      and runs on every matching element, including elements that arrive
 *      later in an AJAX response. This is the extension point: anything jQuery
 *      can do, a behaviour can do.
 *
 *   3. Commands.    The server returns a list of operations and this executes
 *      them, so one response can touch any part of the page. Custom commands
 *      register client-side by name, which means a response can only invoke
 *      what you have already allowed; there is deliberately no "eval" command.
 *
 * MIT licensed. Part of innovin/phpvin.
 */
(function (factory) {
    'use strict';

    if (typeof define === 'function' && define.amd) {
        define(['jquery'], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory(require('jquery'));
    } else {
        factory(window.jQuery);
    }
}(function ($) {
    'use strict';

    if (!$) {
        console.error(
            'phpvin.js needs jQuery, and jQuery was not found. ' +
            'Load it before this file: <script src="/js/jquery.min.js"></script>'
        );
        return;
    }

    var phpvin = {
        version: '0.2.0',

        selectors: {
            remoteForm: 'form[data-remote]',
            remoteLink: 'a[data-remote]',
            methodLink: 'a[data-method]',
            confirmable: '[data-confirm]',
            behavior: '[data-behavior]'
        },

        csrfHeader: 'X-CSRF-Token',
        locationHeader: 'X-Phpvin-Location',
        triggerHeader: 'X-Phpvin-Trigger',
        commandsType: 'application/vnd.phpvin.commands+json',

        /** Registered behaviours, by name. */
        behaviors: {},

        /** Registered commands, by name. */
        commands: {},

        /** Registered swap strategies, by name. */
        swaps: {}
    };

    // ---------------------------------------------------------------- CSRF

    /**
     * Read the token fresh each time, so a regenerated session (after login,
     * say) is picked up without a page reload.
     */
    phpvin.csrfToken = function () {
        return $('meta[name="csrf-token"]').attr('content') || null;
    };

    $.ajaxPrefilter(function (options, originalOptions, xhr) {
        var method = (options.type || 'GET').toUpperCase();
        var token = phpvin.csrfToken();

        if (token && !options.crossDomain && $.inArray(method, ['GET', 'HEAD', 'OPTIONS']) === -1) {
            xhr.setRequestHeader(phpvin.csrfHeader, token);
        }

        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    });

    // ----------------------------------------------------------- behaviours

    /**
     * Register a behaviour.
     *
     *     phpvin.behavior('char-counter', function ($el, data) {
     *         var $out = $(data.output);
     *
     *         function count() { $out.text($el.val().length + '/' + data.max); }
     *
     *         $el.on('input.counter', count);
     *         count();
     *
     *         return { destroy: function () { $el.off('.counter'); } };
     *     });
     *
     *     <input data-behavior="char-counter" data-output="#n" data-max="200">
     *
     * The element's data-* attributes arrive as the second argument. Returning
     * an object with a `destroy` method registers teardown, which runs when
     * jQuery removes the element, including when a swap replaces it.
     *
     * Several behaviours can share an element: data-behavior="one two".
     */
    phpvin.behavior = function (name, handler) {
        if (typeof handler !== 'function') {
            throw new Error('phpvin.behavior("' + name + '") needs a function.');
        }

        phpvin.behaviors[name] = handler;

        // Registering after the page has loaded still picks up what is already
        // on screen, so load order does not matter.
        if (phpvin.started) {
            applyBehaviors($(document), name);
        }

        return phpvin;
    };

    function applyBehaviors($root, only) {
        var $elements = $root.find(phpvin.selectors.behavior).addBack(phpvin.selectors.behavior);

        $elements.each(function () {
            var $el = $(this);
            var names = ($el.data('behavior') || '').toString().split(/\s+/);
            var started = $el.data('phpvin-started') || {};
            var instances = $el.data('phpvin-instances') || [];

            $.each(names, function (_, name) {
                if (!name || (only && name !== only) || started[name]) {
                    return;
                }

                var handler = phpvin.behaviors[name];

                if (!handler) {
                    return; // may be registered later; applyBehaviors re-runs then
                }

                started[name] = true;

                try {
                    var instance = handler.call(this, $el, $el.data());

                    if (instance) {
                        instances.push(instance);
                    }
                } catch (error) {
                    console.error('phpvin: behaviour "' + name + '" threw.', error);
                }
            });

            $el.data('phpvin-started', started).data('phpvin-instances', instances);
        });
    }

    /**
     * Run behaviours over a subtree. Called automatically on ready and after
     * every swap; call it yourself if you insert HTML by some other route.
     */
    phpvin.start = function (root) {
        applyBehaviors($(root || document));

        $(root || document).trigger('phpvin:load');

        return phpvin;
    };

    // jQuery's own teardown hook, the supported way to notice removal.
    var cleanData = $.cleanData;

    $.cleanData = function (elements) {
        for (var i = 0; elements[i] != null; i++) {
            var instances = $.data(elements[i], 'phpvin-instances');

            if (instances) {
                $.each(instances, function (_, instance) {
                    if (instance && typeof instance.destroy === 'function') {
                        try {
                            instance.destroy();
                        } catch (error) {
                            console.error('phpvin: behaviour teardown threw.', error);
                        }
                    }
                });
            }
        }

        return cleanData.apply(this, arguments);
    };

    // -------------------------------------------------------------- swapping

    function registerSwap(name, fn) {
        phpvin.swaps[name] = fn;
    }

    registerSwap('replace', function ($target, $incoming) { $target.replaceWith($incoming); });
    registerSwap('inner',   function ($target, $incoming) { $target.empty().append($incoming); });
    registerSwap('append',  function ($target, $incoming) { $target.append($incoming); });
    registerSwap('prepend', function ($target, $incoming) { $target.prepend($incoming); });
    registerSwap('before',  function ($target, $incoming) { $target.before($incoming); });
    registerSwap('after',   function ($target, $incoming) { $target.after($incoming); });
    registerSwap('remove',  function ($target) { $target.remove(); });
    registerSwap('none',    function () {});

    /**
     * Register a swap strategy, usable as data-swap="name".
     *
     *     phpvin.swap.register('fade-in', function ($target, $incoming) {
     *         $incoming.hide();
     *         $target.append($incoming);
     *         $incoming.fadeIn(200);
     *     });
     */
    phpvin.swap = function ($target, html, mode) {
        mode = mode || 'inner';

        var strategy = phpvin.swaps[mode];

        if (!strategy) {
            console.warn('phpvin: unknown data-swap value "' + mode + '", using inner.');
            strategy = phpvin.swaps.inner;
        }

        var $incoming = html == null || html === ''
            ? $()
            : $($.parseHTML($.trim(String(html)), document, true));

        strategy($target, $incoming);

        // Anything that just landed gets its behaviours wired up.
        if ($incoming.length) {
            phpvin.start($incoming.parent().length ? $incoming.parent() : $incoming);
        }

        return $incoming;
    };

    phpvin.swap.register = registerSwap;

    // -------------------------------------------------------------- commands

    /**
     * Register a command the server may invoke by name.
     *
     *     phpvin.command('confetti', function (args) { ... });
     *
     * From PHP: Commands::make()->call('confetti', ['count' => 50]);
     */
    phpvin.command = function (name, fn) {
        phpvin.commands[name] = fn;

        return phpvin;
    };

    function target(selector) {
        return selector ? $(selector) : $();
    }

    phpvin.command('append',      function (c) { phpvin.swap(target(c.selector), c.html, 'append'); });
    phpvin.command('prepend',     function (c) { phpvin.swap(target(c.selector), c.html, 'prepend'); });
    phpvin.command('replace',     function (c) { phpvin.swap(target(c.selector), c.html, 'replace'); });
    phpvin.command('inner',       function (c) { phpvin.swap(target(c.selector), c.html, 'inner'); });
    phpvin.command('before',      function (c) { phpvin.swap(target(c.selector), c.html, 'before'); });
    phpvin.command('after',       function (c) { phpvin.swap(target(c.selector), c.html, 'after'); });
    phpvin.command('remove',      function (c) { target(c.selector).remove(); });

    phpvin.command('addClass',    function (c) { target(c.selector).addClass(c.value); });
    phpvin.command('removeClass', function (c) { target(c.selector).removeClass(c.value); });
    phpvin.command('toggleClass', function (c) { target(c.selector).toggleClass(c.value); });

    phpvin.command('attr', function (c) {
        if (c.value === null) {
            target(c.selector).removeAttr(c.name);
        } else {
            target(c.selector).attr(c.name, c.value);
        }
    });

    phpvin.command('text',  function (c) { target(c.selector).text(c.value); });
    phpvin.command('value', function (c) { target(c.selector).val(c.value); });
    phpvin.command('focus', function (c) { target(c.selector).trigger('focus'); });

    phpvin.command('scrollTo', function (c) {
        var $el = target(c.selector);

        if ($el.length && $el[0].scrollIntoView) {
            $el[0].scrollIntoView({ behavior: c.smooth === false ? 'auto' : 'smooth', block: 'start' });
        }
    });

    phpvin.command('trigger', function (c) {
        $(c.selector || document).trigger(c.event, [c.detail]);
    });

    phpvin.command('redirect', function (c) { window.location.href = c.url; });
    phpvin.command('reload',   function () { window.location.reload(); });
    phpvin.command('log',      function (c) { console.log('phpvin:', c.message); });

    /**
     * Invoke a command registered with phpvin.command(). Unknown names are
     * reported rather than silently ignored, and nothing else in the list is
     * abandoned because one entry failed.
     */
    phpvin.command('call', function (c) {
        var fn = phpvin.commands[c.name];

        if (typeof fn !== 'function') {
            console.error(
                'phpvin: the server asked for command "' + c.name + '", which is not registered. ' +
                'Register it with phpvin.command("' + c.name + '", fn).'
            );
            return;
        }

        fn(c.arguments || {});
    });

    /**
     * Execute a list of commands from the server.
     */
    phpvin.run = function (commands) {
        if (!$.isArray(commands)) {
            console.error('phpvin: expected a list of commands, got', commands);
            return phpvin;
        }

        $.each(commands, function (_, command) {
            var fn = phpvin.commands[command && command.command];

            if (typeof fn !== 'function') {
                console.error('phpvin: unknown command', command);
                return;
            }

            try {
                fn(command);
            } catch (error) {
                console.error('phpvin: command "' + command.command + '" threw.', error);
            }
        });

        return phpvin;
    };

    // ------------------------------------------------------- error handling

    phpvin.showErrors = function ($form, errors) {
        phpvin.clearErrors($form);

        $.each(errors || {}, function (field, messages) {
            var message = $.isArray(messages) ? messages[0] : messages;

            $form.find('[data-error-for="' + field + '"]').text(message).show();
            $form.find('[name="' + field + '"]').addClass('is-invalid').attr('aria-invalid', 'true');
        });
    };

    phpvin.clearErrors = function ($form) {
        $form.find('[data-error-for]').text('').hide();
        $form.find('.is-invalid').removeClass('is-invalid').removeAttr('aria-invalid');
    };

    // --------------------------------------------------------- the AJAX core

    function resolveTarget($el) {
        var selector = $el.data('target');

        return selector ? $(selector) : $el;
    }

    function disable($form) {
        var $buttons = $form.find('button[type="submit"], input[type="submit"]').filter(':not(:disabled)');

        $buttons.each(function () {
            var $button = $(this);
            var replacement = $button.data('disable-with');

            $button.data('phpvin-original', $button.is('input') ? $button.val() : $button.html());

            if (replacement !== undefined) {
                if ($button.is('input')) {
                    $button.val(replacement);
                } else {
                    $button.html(replacement);
                }
            }

            $button.prop('disabled', true);
        });

        return $buttons;
    }

    function enable($buttons) {
        $buttons.each(function () {
            var $button = $(this);
            var original = $button.data('phpvin-original');

            if (original !== undefined) {
                if ($button.is('input')) {
                    $button.val(original);
                } else {
                    $button.html(original);
                }
            }

            $button.prop('disabled', false);
        });
    }

    function fireServerTriggers(xhr) {
        var header = xhr.getResponseHeader(phpvin.triggerHeader);

        if (!header) {
            return;
        }

        try {
            var events = JSON.parse(header);

            $.each(events, function (name, detail) {
                $(document).trigger(name, [detail]);
            });
        } catch (error) {
            console.error('phpvin: could not parse ' + phpvin.triggerHeader, header, error);
        }
    }

    /**
     * Issue a request and apply whatever comes back: HTML to swap, or a
     * command list to execute. Exposed so you can use it from your own code.
     *
     *     phpvin.request($('#panel'), { url: '/panel', method: 'GET' });
     */
    phpvin.request = function ($el, options) {
        var $target = options.target ? $(options.target) : resolveTarget($el);
        var mode = options.swap || $el.data('swap');
        var $buttons = $el.is('form') ? disable($el) : $();

        var before = $.Event('phpvin:before');
        $el.trigger(before, [options]);

        if (before.isDefaultPrevented()) {
            enable($buttons);
            return;
        }

        return $.ajax({
            url: options.url,
            type: options.method || 'GET',
            data: options.data
            // No dataType: jQuery infers it from Content-Type, which is how a
            // command list and an HTML fragment tell themselves apart.
        }).done(function (body, status, xhr) {
            fireServerTriggers(xhr);

            var location = xhr.getResponseHeader(phpvin.locationHeader);

            if (location) {
                window.location.href = location;
                return;
            }

            if ($el.is('form')) {
                phpvin.clearErrors($el);

                if ($el.data('reset') !== false) {
                    $el[0].reset();
                }
            }

            var contentType = xhr.getResponseHeader('content-type') || '';

            if (contentType.indexOf(phpvin.commandsType) !== -1) {
                phpvin.run(typeof body === 'string' ? JSON.parse(body) : body);
                $el.trigger('phpvin:success', [body, $()]);
                return;
            }

            var $inserted = phpvin.swap($target, body, mode);

            $el.trigger('phpvin:success', [body, $inserted]);
        }).fail(function (xhr) {
            fireServerTriggers(xhr);

            var payload = null;

            try {
                payload = JSON.parse(xhr.responseText);
            } catch (error) {
                payload = null;
            }

            if (xhr.status === 422 && payload && payload.errors && $el.is('form')) {
                phpvin.showErrors($el, payload.errors);
                $el.trigger('phpvin:error', [xhr, payload]);

                return;
            }

            $el.trigger('phpvin:error', [xhr, payload]);

            console.error('phpvin: request failed with ' + xhr.status, payload || xhr.responseText);
        }).always(function () {
            enable($buttons);
            $el.trigger('phpvin:complete');
        });
    };

    /**
     * jQuery plugin surface.
     *
     *     $('#panel').phpvin('load', '/panel');   // fetch into the element
     *     $(html).phpvin();                       // wire up behaviours
     */
    $.fn.phpvin = function (action, url, options) {
        if (action === 'load') {
            return this.each(function () {
                phpvin.request($(this), $.extend({ url: url, method: 'GET' }, options || {}));
            });
        }

        return this.each(function () {
            phpvin.start($(this));
        });
    };

    // ------------------------------------------------------------- behaviours

    $(document)

        // data-confirm runs first and stops the rest of the chain when declined.
        .on('click', phpvin.selectors.confirmable, function (event) {
            var message = $(this).data('confirm');

            if (message && !window.confirm(message)) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        })

        .on('submit', phpvin.selectors.remoteForm, function (event) {
            event.preventDefault();

            var $form = $(this);

            phpvin.request($form, {
                url: $form.attr('action') || window.location.href,
                method: ($form.attr('method') || 'GET').toUpperCase(),
                data: $form.serialize()
            });
        })

        .on('click', phpvin.selectors.methodLink, function (event) {
            event.preventDefault();

            var $link = $(this);
            var method = ($link.data('method') || 'POST').toUpperCase();
            var data = {};

            if ($.inArray(method, ['PUT', 'PATCH', 'DELETE']) !== -1) {
                data._method = method;
            }

            var token = phpvin.csrfToken();

            if (token) {
                data._token = token;
            }

            if ($link.is('[data-remote]') || $link.is('[data-target]')) {
                phpvin.request($link, { url: $link.attr('href'), method: 'POST', data: data });
                return;
            }

            // No target: fall back to a real navigation, so data-method works
            // on plain pages too.
            var $form = $('<form>', { method: 'post', action: $link.attr('href') }).hide();

            $.each(data, function (name, value) {
                $form.append($('<input>', { type: 'hidden', name: name, value: value }));
            });

            $form.appendTo('body').submit();
        })

        .on('click', phpvin.selectors.remoteLink, function (event) {
            if ($(this).is('[data-method]')) {
                return; // handled above
            }

            event.preventDefault();

            var $link = $(this);

            phpvin.request($link, { url: $link.attr('href'), method: 'GET', data: null });
        });

    $(function () {
        phpvin.started = true;
        phpvin.start(document);
    });

    window.phpvin = phpvin;

    return phpvin;
}));
