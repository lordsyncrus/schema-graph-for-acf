/* global jQuery, SchemaGraph */
(function ($) {
    'use strict';

    var $root, cpt, acfFields, definition;
    var $rowsBody;
    var propsCache = {}; // type → [properties]

    var SOURCE_KINDS = [
        { value: 'token',    label: 'Token dinamico' },
        { value: 'acf',      label: 'Campo ACF' },
        { value: 'static',   label: 'Valore fisso (static)' },
        { value: 'fallback', label: 'Fallback (lista ordinata)' },
        { value: 'collect',  label: 'Collect (lista campi ACF)' },
        { value: 'relation', label: 'Relation (ACF relationship)' }
    ];

    $(function () {
        $root = $('.schema-graph-edit');
        if (!$root.length) return;

        cpt        = $root.data('cpt');
        acfFields  = $root.data('acf-fields') || [];
        definition = $root.data('definition') || {};

        $rowsBody = $('#sg-rows');

        renderExistingMap();

        // Carica suggerimenti proprietà se @type già scelto
        var initialType = $('#sg-type').val();
        if (initialType) loadPropertySuggestions(initialType);

        $('#sg-type').on('change input', function () {
            var v = $(this).val();
            if (v) loadPropertySuggestions(v);
        });

        $('#sg-add-row').on('click', function () {
            addRow();
        });

        $rowsBody.on('change', '.sg-kind', function () {
            var $row = $(this).closest('tr');
            $row.find('.sg-value-cell').html(renderValueCell($(this).val(), null));
        });

        $rowsBody.on('click', '.sg-remove', function () {
            $(this).closest('tr').remove();
        });

        $rowsBody.sortable({
            handle: '.sg-handle',
            items: '> tr',
            placeholder: 'sg-row sg-placeholder',
            helper: function (e, tr) {
                var $orig = tr.children();
                var $helper = tr.clone();
                $helper.children().each(function (i) {
                    $(this).width($orig.eq(i).width());
                });
                return $helper;
            }
        });

        $('#schema-graph-form').on('submit', function () {
            $('#schema-graph-map-json').val(JSON.stringify(serializeMap()));
        });

        // Click su campo ACF → inserisce nel primo input ACF della riga focused
        $('.schema-graph-fields li').on('click', function () {
            var name = $(this).find('code').text();
            var $target = $(document.activeElement);
            if ($target.is('input[type="text"]')) {
                insertAtCursor($target[0], name);
                $target.trigger('change');
            }
        });

        $('#sg-preview-btn').on('click', runPreview);
    });

    function renderExistingMap() {
        var map = definition.map || {};
        if (typeof map !== 'object' || Array.isArray(map)) map = {};
        var keys = Object.keys(map);
        if (!keys.length) {
            // Pre-popola con una riga vuota
            addRow();
            return;
        }
        keys.forEach(function (prop) {
            addRow(prop, map[prop]);
        });
    }

    function addRow(prop, source) {
        var kind = detectKind(source);
        var $tr = $(rowTemplate(prop || '', kind));
        $rowsBody.append($tr);
        $tr.find('.sg-value-cell').html(renderValueCell(kind, source));
    }

    function rowTemplate(prop, kind) {
        var kindOptions = SOURCE_KINDS.map(function (k) {
            return '<option value="' + k.value + '"' + (k.value === kind ? ' selected' : '') + '>' + escapeHtml(k.label) + '</option>';
        }).join('');

        return '' +
            '<tr class="sg-row">' +
                '<td class="sg-handle">⋮⋮</td>' +
                '<td><input type="text" class="sg-prop" list="sg-prop-suggestions" value="' + escapeHtml(prop) + '" placeholder="nome proprietà (es. name)"></td>' +
                '<td><select class="sg-kind">' + kindOptions + '</select></td>' +
                '<td class="sg-value-cell"></td>' +
                '<td class="sg-col-actions"><button type="button" class="button-link button-link-delete sg-remove">' + escapeHtml(SchemaGraph.i18n.remove) + '</button></td>' +
            '</tr>';
    }

    function detectKind(source) {
        if (source === null || source === undefined) return 'token';
        if (typeof source === 'string') {
            return source.charAt(0) === ':' ? 'token' : 'acf';
        }
        if (typeof source === 'object') {
            if ('static' in source)   return 'static';
            if ('fallback' in source) return 'fallback';
            if ('collect' in source)  return 'collect';
            if ('relation' in source) return 'relation';
            if ('acf' in source)      return 'acf';
        }
        return 'token';
    }

    function renderValueCell(kind, source) {
        switch (kind) {
            case 'token':    return renderTokenInput(source);
            case 'acf':      return renderAcfInput(source);
            case 'static':   return renderStaticInput(source);
            case 'fallback': return renderListInput(source && source.fallback, 'token-or-field', 'un valore per riga (es. :excerpt)');
            case 'collect':  return renderListInput(source && source.collect, 'field', 'un nome campo ACF per riga');
            case 'relation': return renderRelationInput(source);
        }
        return '';
    }

    function renderTokenInput(source) {
        var current = (typeof source === 'string') ? source : '';
        var opts = Object.keys(SchemaGraph.tokens).map(function (t) {
            return '<option value="' + t + '"' + (t === current ? ' selected' : '') + '>' + t + ' — ' + escapeHtml(SchemaGraph.tokens[t]) + '</option>';
        }).join('');
        return '<select class="sg-val-token"><option value="">—</option>' + opts + '</select>';
    }

    function renderAcfInput(source) {
        var name = '';
        var split = false;
        if (typeof source === 'string') {
            name = source;
        } else if (source && typeof source === 'object') {
            name = source.acf || '';
            split = !!source.split;
        }
        return '' +
            '<input type="text" class="sg-val-acf-name" value="' + escapeHtml(name) + '" placeholder="nome campo ACF">' +
            '<label class="sg-checkbox-inline"><input type="checkbox" class="sg-val-acf-split"' + (split ? ' checked' : '') + '> split CSV</label>';
    }

    function renderStaticInput(source) {
        var val = '';
        if (source && 'static' in source) {
            var s = source.static;
            val = (typeof s === 'string') ? s : JSON.stringify(s, null, 2);
        }
        return '<textarea class="sg-val-static" rows="3" placeholder="valore fisso — stringa o JSON. I token :tipo verranno risolti.">' + escapeHtml(val) + '</textarea>';
    }

    function renderListInput(items, hint, placeholder) {
        var lines = Array.isArray(items) ? items.join('\n') : '';
        return '<textarea class="sg-val-list" rows="3" placeholder="' + escapeHtml(placeholder) + '">' + escapeHtml(lines) + '</textarea>' +
               '<p class="description" style="margin:4px 0 0;">' + escapeHtml(placeholder) + '</p>';
    }

    function renderRelationInput(source) {
        source = source || {};
        return '' +
            '<div class="sg-value-multi">' +
                '<div class="sg-value-multi-row">' +
                    '<input type="text" class="sg-val-rel" value="' + escapeHtml(source.relation || '') + '" placeholder="nome campo relationship">' +
                    '<input type="text" class="sg-val-as-type" value="' + escapeHtml(source.as_type || '') + '" placeholder="as_type (es. MusicAlbum)">' +
                '</div>' +
                '<div class="sg-value-multi-row">' +
                    '<input type="text" class="sg-val-rel-suffix" value="' + escapeHtml(source.id_suffix || '') + '" placeholder="id_suffix (es. #album)">' +
                    '<label class="sg-checkbox-inline"><input type="checkbox" class="sg-val-rel-single"' + (source.single ? ' checked' : '') + '> single</label>' +
                '</div>' +
            '</div>';
    }

    function serializeMap() {
        var out = {};
        $rowsBody.find('tr.sg-row').each(function () {
            var $row = $(this);
            var prop = $row.find('.sg-prop').val().trim();
            if (!prop) return;
            var kind = $row.find('.sg-kind').val();
            var source = readRow($row, kind);
            if (source === null || source === undefined || source === '') return;
            out[prop] = source;
        });
        return out;
    }

    function readRow($row, kind) {
        switch (kind) {
            case 'token':
                return $row.find('.sg-val-token').val() || null;

            case 'acf': {
                var name  = $row.find('.sg-val-acf-name').val().trim();
                var split = $row.find('.sg-val-acf-split').is(':checked');
                if (!name) return null;
                return split ? { acf: name, split: true } : name;
            }

            case 'static': {
                var raw = $row.find('.sg-val-static').val();
                if (!raw || !raw.trim()) return null;
                var parsed;
                try { parsed = JSON.parse(raw); } catch (e) { parsed = raw; }
                return { 'static': parsed };
            }

            case 'fallback': {
                var lines = splitLines($row.find('.sg-val-list').val());
                if (!lines.length) return null;
                return { fallback: lines };
            }

            case 'collect': {
                var lines2 = splitLines($row.find('.sg-val-list').val());
                if (!lines2.length) return null;
                return { collect: lines2 };
            }

            case 'relation': {
                var rel    = $row.find('.sg-val-rel').val().trim();
                var asType = $row.find('.sg-val-as-type').val().trim();
                if (!rel || !asType) return null;
                var out = { relation: rel, as_type: asType };
                var suffix = $row.find('.sg-val-rel-suffix').val().trim();
                if (suffix) out.id_suffix = suffix;
                if ($row.find('.sg-val-rel-single').is(':checked')) out.single = true;
                return out;
            }
        }
        return null;
    }

    function splitLines(str) {
        if (!str) return [];
        return str.split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
    }

    function loadPropertySuggestions(type) {
        if (propsCache[type]) {
            renderPropDatalist(propsCache[type]);
            return;
        }
        $.get(SchemaGraph.ajaxUrl, {
            action: 'schema_graph_properties',
            nonce:  SchemaGraph.propsNonce,
            type:   type
        }).done(function (res) {
            if (!res || !res.success) return;
            propsCache[type] = res.data.properties || [];
            renderPropDatalist(propsCache[type]);
        });
    }

    function renderPropDatalist(props) {
        var $dl = $('#sg-prop-suggestions');
        var html = props.map(function (p) {
            return '<option value="' + escapeHtml(p) + '"></option>';
        }).join('');
        $dl.html(html);
    }

    function runPreview() {
        var $out  = $('#sg-preview-output');
        var $meta = $('#sg-preview-meta');
        $meta.text('…');
        $out.hide();

        var def = {
            type:      $('#sg-type').val(),
            id_suffix: $('#sg-id-suffix').val(),
            og_type:   $('#sg-og-type').val(),
            map:       serializeMap()
        };

        $.post(SchemaGraph.ajaxUrl, {
            action:     'schema_graph_preview',
            nonce:      SchemaGraph.previewNonce,
            cpt:        cpt,
            definition: JSON.stringify(def)
        }).done(function (res) {
            if (!res || !res.success) {
                $meta.text('Errore: ' + (res && res.data ? res.data : 'unknown'));
                return;
            }
            $meta.text('Post pilota: #' + res.data.post_id + ' — ' + res.data.title);
            $out.text(JSON.stringify(res.data.node, null, 2)).show();
        }).fail(function (xhr) {
            var msg = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : xhr.statusText;
            $meta.text('Errore: ' + msg);
        });
    }

    function insertAtCursor(input, text) {
        var start = input.selectionStart || 0;
        var end   = input.selectionEnd   || 0;
        var val   = input.value;
        input.value = val.substring(0, start) + text + val.substring(end);
        input.selectionStart = input.selectionEnd = start + text.length;
        input.focus();
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
})(jQuery);
