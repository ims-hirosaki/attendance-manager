/**
 * 勤怠管理（統合版） - admin.js
 * 長距離（chokyo）・地場事務（jiba）・休日マスタ 共通
 */
(function ($) {
    'use strict';

    var currentPage = amData.currentPage;

    /* ================================================================
       共通ユーティリティ
       ================================================================ */
    function parseMin(str) {
        if (!str || str.trim() === '') return 0;
        var parts = str.trim().split(':');
        return (parseInt(parts[0], 10) || 0) * 60 + (parseInt(parts[1], 10) || 0);
    }

    function updateBtnState(selectId, btnId) {
        var val = $(selectId).val();
        $(btnId).prop('disabled', !val);
    }

    function refreshSummary(params, action) {
        $.post(amData.ajaxUrl, $.extend({ action: action, nonce: amData.nonce }, params), function (res) {
            if (!res.success) return;
            var s = res.data;
            $('[data-ms="attendance"]').html(s.attendance + '<span class="am-ms-unit">日</span>');
            $('[data-ms="absent"]').html(s.absent + '<span class="am-ms-unit">日</span>').toggleClass('am-ms-alert', s.absent > 0);
            $('[data-ms="holiday_work"]').html(s.holiday_work + '<span class="am-ms-unit">日</span>').toggleClass('am-ms-warn', s.holiday_work > 0);
            $('[data-ms="unmatched_houtei"]').html(s.unmatched_houtei_days + '<span class="am-ms-unit">日</span> / ' + s.unmatched_houtei_labor_str).toggleClass('am-ms-warn', s.unmatched_houtei_days > 0);
            $('[data-ms="paid_consumed"]').html(s.paid_has_data ? parseFloat(s.paid_consumed).toFixed(1) + '<span class="am-ms-unit">日</span>' : '<span class="am-ms-na">―</span>');
            $('[data-ms="paid_remaining"]').html(s.paid_has_data ? parseFloat(s.paid_remaining).toFixed(1) + '<span class="am-ms-unit">日</span>' : '<span class="am-ms-na">―</span>');
            $('[data-ms="labor"]').html(s.labor_str);
            $('[data-ms="hayatai"]').html(s.hayatai_str || '―').toggleClass('am-ms-warn', s.hayatai_min > 0);
            $('[data-ms="overtime"]').html(s.overtime_str).toggleClass('am-ms-over', s.overtime_min > 0);
        });
    }

    function refreshDailyRows(params, action) {
        $.post(amData.ajaxUrl, $.extend({ action: action, nonce: amData.nonce }, params), function (res) {
            if (!res.success) return;

            // 警告アラートを更新
            var alerts = res.data.alerts || [];
            var $alertWrap = $('.am-alerts');
            if (alerts.length) {
                var html = '';
                $.each(alerts, function (_, a) {
                    var cls = a.type === 'error' ? 'am-alert-error' : 'am-alert-warn';
                    var icon = a.type === 'error' ? 'dashicons-warning' : 'dashicons-info';
                    html += '<div class="am-alert ' + cls + '">'
                        + '<span class="dashicons ' + icon + '"></span>'
                        + $('<span>').text(a.message).html()
                        + '</div>';
                });
                if ($alertWrap.length) {
                    $alertWrap.html(html).show();
                } else {
                    $('.am-info-table').after('<div class="am-alerts">' + html + '</div>');
                }
            } else {
                $alertWrap.empty().hide();
            }

            // 日次行を更新
            $.each(res.data.rows, function (_, r) {
                var $tr = $('tbody tr[data-date="' + r.date + '"]');
                if (!$tr.length) return;

                // 勤怠種別セレクトを更新
                if (r.kintai_type !== undefined) {
                    $tr.find('.am-kintai-select').val(r.kintai_type);
                    $tr.attr('data-auto', 'true');
                }

                // 法定休出勤・所定休出勤バッジを更新
                $tr.find('.am-badge-houtei-kinmu').remove();
                $tr.find('.am-badge-shitei-kinmu').remove();
                if (r.houtei_kinmu) {
                    $tr.find('.am-date-row').after('<span class="am-badge-houtei-kinmu">法定休出勤</span>');
                }
                if (r.shitei_kinmu) {
                    $tr.find('.am-date-row').after('<span class="am-badge-shitei-kinmu">所定休出勤</span>');
                }

                // 時間データを更新
                $tr.find('td:nth-child(3)').text(r.start_time);
                $tr.find('td:nth-child(4)').text(r.end_time);
                $tr.find('td:nth-child(5)').text(r.kousoku_min);
                $tr.find('td:nth-child(6)').text(r.labor_min);
                $tr.find('td:nth-child(7)').text(r.drive_min);
                $tr.find('td:nth-child(8)').text(r.cargo_min);
                $tr.find('td:nth-child(9)').text(r.break_min);
                $tr.find('td:nth-child(10)').text(r.midnight_min);
                $tr.find('td:nth-child(11)').text(r.overtime_min);
            });
        });
    }

    function refreshWeeklyRows(params, action) {
        $.post(amData.ajaxUrl, $.extend({ action: action, nonce: amData.nonce }, params), function (res) {
            if (!res.success) return;
            var $rows = $('.am-weekly-table tbody tr');
            var dataRows = res.data.filter(function (r) { return r.label !== '__total__'; });
            var totalRow = res.data.find(function (r) { return r.label === '__total__'; });
            dataRows.forEach(function (r, i) {
                var $tr = $rows.eq(i);
                if (!$tr.length) return;
                if (r.is_prev_carry) {
                    $tr.find('td:nth-child(11)').text(r.day_overtime_min);
                    $tr.find('td:nth-child(12)').text(r.week_overtime_min || '');
                    return;
                }
                $tr.find('td:nth-child(5)').text(r.kousoku_min);
                $tr.find('td:nth-child(6)').text(r.labor_min);
                $tr.find('td:nth-child(7)').text(r.drive_min);
                $tr.find('td:nth-child(8)').text(r.cargo_min);
                $tr.find('td:nth-child(9)').text(r.break_min);
                $tr.find('td:nth-child(10)').text(r.midnight_min);
                $tr.find('td:nth-child(11)').text(r.day_overtime_min);
                if (r.is_carryover_badge) {
                    $tr.find('td:nth-child(12)').html('<span class="am-badge-carryover">次月繰越</span>');
                } else {
                    $tr.find('td:nth-child(12)').text(r.week_overtime_min || '');
                }
                $tr.find('td:nth-child(13)').text(r.confirmed_overtime);
            });
            if (totalRow) {
                var $tfoot = $('.am-weekly-table tfoot tr');
                $tfoot.find('td:nth-child(5)').text(totalRow.kousoku_min);
                $tfoot.find('td:nth-child(6)').text(totalRow.labor_min);
                $tfoot.find('td:nth-child(7)').text(totalRow.drive_min);
                $tfoot.find('td:nth-child(8)').text(totalRow.cargo_min);
                $tfoot.find('td:nth-child(9)').text(totalRow.break_min);
                $tfoot.find('td:nth-child(10)').text(totalRow.midnight_min);
                $tfoot.find('td:nth-child(11)').text(totalRow.day_overtime_min);
                $tfoot.find('td:nth-child(12)').text(totalRow.week_overtime_min);
                $tfoot.find('td:nth-child(13)').text(totalRow.confirmed_overtime);
            }
        });
    }

    /* ================================================================
       所属チップ → 社員セレクトフィルタリング（共通）
       ================================================================ */
    $(document).on('click', '.am-chip', function () {
        var affil = $(this).data('affil');
        $('.am-chip').removeClass('am-chip-active');
        $(this).addClass('am-chip-active');
        var $select = $('.am-select[name="am_crew"], .am-select[name="am_emp"]');
        var $options = $select.find('option[data-affil]');
        if (affil === 'all') { $options.show(); }
        else { $options.each(function () { $(this).data('affil') == affil ? $(this).show() : $(this).hide(); }); }
        var $sel = $select.find('option:selected');
        if ($sel.val() !== '' && $sel.is(':hidden')) $select.val('');
        updateBtnState('#am-select-crew, #am-select-emp', '#am-btn-open, #am-btn-open-jiba');
    });

    $(document).on('change', '.am-kintai-select', function () {
        $(this).closest('tr').attr('data-auto', 'false');
    });

    /* ================================================================
       長距離ページ
       ================================================================ */
    if (currentPage === 'attendance-manager') {

        updateBtnState('#am-select-crew', '#am-btn-open');
        $('#am-select-crew').on('change', function () { updateBtnState('#am-select-crew', '#am-btn-open'); });
        $('#am-select-month').on('change input', function () { updateBtnState('#am-select-crew', '#am-btn-open'); });

        // jibaトグルが操作されたかを追跡
        var jibaToggled = false;
        $(document).on('change', '.am-jiba-input', function () {
            jibaToggled = true;
        });

        $(document).on('click', '#am-btn-save', function () {
            var $btn = $(this);
            var crewCode = $btn.data('crew');
            var month = $btn.data('month');
            var $msg = $('#am-save-message');
            var rows = [];
            $('tbody tr[data-date]').each(function () {
                var $tr = $(this);
                rows.push({
                    date: $tr.data('date'),
                    kintai_type: $tr.find('.am-kintai-select').val() || '',
                    furikae_label: $tr.data('furikae') || '',
                    is_manual: $tr.attr('data-auto') === 'false' ? 1 : 0,
                    jiba: $tr.find('.am-jiba-input').is(':checked') ? 1 : 0,
                    hayatai_min: parseMin($tr.find('.am-hayatai-input').val()),
                    note: $tr.find('.am-note-input').val() || '',
                });
            });
            $btn.prop('disabled', true).text('保存中...');
            $msg.hide();
            $.post(amData.ajaxUrl, {
                action: 'am_chokyo_kintai_save', nonce: amData.nonce, crew_code: crewCode, rows: rows,
            }, function (res) {
                if (res.success) {
                    // jibaトグルが操作された場合はページリロードで確実に再描画
                    var jibaChanged = jibaToggled;
                    if (jibaChanged) {
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> 保存（更新）');
                        alert(res.data.saved + '件を保存しました。\nページを更新して反映します。');
                        location.reload();
                        return;
                    }
                    $msg.text(res.data.saved + '件を保存しました').css({ color: '#2c5f2e', background: '#f0fff0', borderLeft: '4px solid #2c5f2e', padding: '8px 20px' }).show();
                    var p = { crew_code: crewCode, year_month: month };
                    refreshSummary(p, 'am_chokyo_get_monthly_summary');
                    refreshDailyRows(p, 'am_chokyo_get_daily_rows');
                    refreshWeeklyRows(p, 'am_chokyo_get_weekly_rows');
                } else {
                    $msg.text('保存に失敗しました：' + (res.data.message || '')).css({ color: '#7a1a1a', background: '#fff0f0', borderLeft: '4px solid #d63638', padding: '8px 20px' }).show();
                }
            }).fail(function () {
                $msg.text('通信エラーが発生しました').css({ color: '#7a1a1a', background: '#fff0f0', borderLeft: '4px solid #d63638', padding: '8px 20px' }).show();
            }).always(function () {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> 保存（更新）');
                setTimeout(function () { $msg.fadeOut(); }, 4000);
            });
        });
    }

    /* ================================================================
       地場・事務ページ
       ================================================================ */
    if (currentPage === 'attendance-manager-jiba') {

        updateBtnState('#am-select-emp', '#am-btn-open-jiba');
        $('#am-select-emp').on('change', function () { updateBtnState('#am-select-emp', '#am-btn-open-jiba'); });
        $('#am-select-month-jiba').on('change input', function () { updateBtnState('#am-select-emp', '#am-btn-open-jiba'); });

        // chokyoトグルが操作されたかを追跡
        var chokyoToggled = false;
        $(document).on('change', '.am-chokyo-input', function () {
            chokyoToggled = true;
        });

        $(document).on('click', '#am-btn-save-jiba', function () {
            var $btn = $(this);
            var empCode = $btn.data('emp');
            var month = $btn.data('month');
            var $msg = $('#am-save-message-jiba');
            var rows = [];
            $('tbody tr[data-date]').each(function () {
                var $tr = $(this);
                rows.push({
                    date: $tr.data('date'),
                    kintai_type: $tr.find('.am-kintai-select').val() || '',
                    furikae_label: $tr.data('furikae') || '',
                    is_manual: $tr.attr('data-auto') === 'false' ? 1 : 0,
                    chokyo: $tr.find('.am-chokyo-input').is(':checked') ? 1 : 0,
                    hayatai_min: parseMin($tr.find('.am-hayatai-input').val()),
                    note: $tr.find('.am-note-input').val() || '',
                });
            });
            $btn.prop('disabled', true).text('保存中...');
            $msg.hide();
            $.post(amData.ajaxUrl, {
                action: 'am_jiba_kintai_save', nonce: amData.nonce, employee_code: empCode, rows: rows,
            }, function (res) {
                if (res.success) {
                    // chokyoトグルが操作された場合はページリロードで確実に再描画
                    if (chokyoToggled) {
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> 保存（更新）');
                        alert(res.data.saved + '件を保存しました。\nページを更新して反映します。');
                        location.reload();
                        return;
                    }
                    $msg.text(res.data.saved + '件を保存しました').css({ color: '#2c5f2e', background: '#f0fff0', borderLeft: '4px solid #2c5f2e', padding: '8px 20px' }).show();
                    var p = { employee_code: empCode, year_month: month };
                    refreshSummary(p, 'am_jiba_get_monthly_summary');
                    refreshDailyRows(p, 'am_jiba_get_daily_rows');
                    refreshWeeklyRows(p, 'am_jiba_get_weekly_rows');
                } else {
                    $msg.text('保存に失敗しました：' + (res.data.message || '')).css({ color: '#7a1a1a', background: '#fff0f0', borderLeft: '4px solid #d63638', padding: '8px 20px' }).show();
                }
            }).fail(function () {
                $msg.text('通信エラーが発生しました').css({ color: '#7a1a1a', background: '#fff0f0', borderLeft: '4px solid #d63638', padding: '8px 20px' }).show();
            }).always(function () {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> 保存（更新）');
                setTimeout(function () { $msg.fadeOut(); }, 4000);
            });
        });
    }

    /* ================================================================
       休日マスタページ
       ================================================================ */
    if (currentPage === 'attendance-manager-settings') {

        var _editingId = 0;

        function hmShowMessage(msg, isError) {
            var $m = $('#hm-message');
            $m.text(msg).css('color', isError ? '#d63638' : '#2c5f2e');
            setTimeout(function () { $m.text(''); }, 4000);
        }

        function hmReloadTable() {
            $.post(amData.ajaxUrl, { action: 'am_holiday_get_rules', nonce: amData.nonce }, function (res) {
                if (!res.success) return;
                var $tbody = $('#hm-rule-tbody');
                var dowLabels = ['日', '月', '火', '水', '木', '金', '土'];
                $tbody.empty();
                if (!res.data.length) {
                    $tbody.append('<tr><td colspan="5" style="text-align:center;color:#aaa;padding:24px;">登録済みルールはありません</td></tr>');
                    return;
                }
                $.each(res.data, function (i, r) {
                    var weeks = r.week_numbers.split(',').join('・');
                    var bgToggle = r.is_active == 1 ? '#aaa' : '#2c5f2e';
                    $tbody.append(
                        '<tr data-id="' + r.id + '">' +
                        '<td>' + $('<span>').text(r.affiliation_name).html() + '</td>' +
                        '<td>' + dowLabels[parseInt(r.day_of_week)] + '曜日</td>' +
                        '<td>第' + weeks + '週</td>' +
                        '<td><span class="hm-status ' + (r.is_active == 1 ? 'hm-active' : 'hm-inactive') + '">' + (r.is_active == 1 ? '有効' : '無効') + '</span></td>' +
                        '<td>' +
                        '<button class="am-btn hm-btn-edit" style="height:30px;padding:0 12px;font-size:12px;background:#2e6da4;color:#fff;" data-id="' + r.id + '" data-affil="' + r.affiliation_id + '" data-dow="' + r.day_of_week + '" data-weeks="' + r.week_numbers + '">編集</button>' +
                        '<button class="am-btn hm-btn-toggle" style="height:30px;padding:0 12px;font-size:12px;background:' + bgToggle + ';color:#fff;margin-left:4px;" data-id="' + r.id + '" data-active="' + r.is_active + '">' + (r.is_active == 1 ? '無効化' : '有効化') + '</button>' +
                        '<button class="am-btn hm-btn-delete" style="height:30px;padding:0 12px;font-size:12px;background:#d63638;color:#fff;margin-left:4px;" data-id="' + r.id + '">削除</button>' +
                        '</td></tr>'
                    );
                });
            });
        }

        $(document).on('click', '#hm-btn-save', function () {
            var affilId = $('#hm-affiliation').val();
            var dow = $('#hm-dow').val();
            var weeks = $('#hm-weeks').val().trim();
            if (!affilId || weeks === '') { hmShowMessage('所属と対象週は必須です', true); return; }
            $.post(amData.ajaxUrl, { action: 'am_holiday_save_rule', nonce: amData.nonce, id: _editingId, affiliation_id: affilId, day_of_week: dow, week_numbers: weeks }, function (res) {
                if (res.success) { hmShowMessage('保存しました', false); hmResetForm(); hmReloadTable(); }
                else hmShowMessage(res.data.message || '保存に失敗しました', true);
            });
        });

        $(document).on('click', '#hm-btn-cancel', function () { hmResetForm(); });

        function hmResetForm() {
            _editingId = 0;
            $('#hm-affiliation').val(''); $('#hm-dow').val('0'); $('#hm-weeks').val('');
            $('#hm-btn-cancel').hide(); $('#hm-btn-save').text('保存');
        }

        $(document).on('click', '.hm-btn-edit', function () {
            var $btn = $(this); _editingId = parseInt($btn.data('id'));
            $('#hm-affiliation').val($btn.data('affil')); $('#hm-dow').val($btn.data('dow')); $('#hm-weeks').val($btn.data('weeks'));
            $('#hm-btn-cancel').show(); $('#hm-btn-save').text('更新');
            $('html, body').animate({ scrollTop: 0 }, 300);
        });

        $(document).on('click', '.hm-btn-toggle', function () {
            var $btn = $(this); var newActive = parseInt($btn.data('active')) === 1 ? 0 : 1;
            $.post(amData.ajaxUrl, { action: 'am_holiday_toggle_rule', nonce: amData.nonce, id: parseInt($btn.data('id')), is_active: newActive }, function (res) { if (res.success) hmReloadTable(); });
        });

        $(document).on('click', '.hm-btn-delete', function () {
            if (!window.confirm('このルールを削除しますか？')) return;
            $.post(amData.ajaxUrl, { action: 'am_holiday_delete_rule', nonce: amData.nonce, id: parseInt($(this).data('id')) }, function (res) { if (res.success) hmReloadTable(); });
        });

        /* ================================================================
           種別管理
           ================================================================ */

        function jtLoad() {
            $.post(amData.ajaxUrl, { action: 'am_jobtype_get', nonce: amData.nonce }, function (res) {
                $('#jt-loading').hide();
                if (!res.success || !res.data.length) {
                    $('#jt-empty').show();
                    return;
                }

                var $tbody = $('#jt-tbody').empty();
                $.each(res.data, function (i, jt) {
                    var isChokyo = jt.category === 'chokyo';
                    var isJiba = jt.category === 'jiba';
                    var unset = jt.category === '';

                    var $tr = $(
                        '<tr data-name="' + $('<span>').text(jt.name).html() + '">' +
                        '<td style="text-align:left;padding-left:20px;font-weight:600;">' + $('<span>').text(jt.name).html() + '</td>' +
                        '<td style="text-align:center;">' +
                        '<label style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;">' +
                        '<input type="radio" name="jt_cat_' + i + '" value="chokyo" ' + (isChokyo ? 'checked' : '') + '> 長距離' +
                        '</label></td>' +
                        '<td style="text-align:center;">' +
                        '<label style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;">' +
                        '<input type="radio" name="jt_cat_' + i + '" value="jiba" ' + (isJiba ? 'checked' : '') + '> 地場・事務' +
                        '</label></td>' +
                        '<td style="text-align:center;">' +
                        '<span class="jt-status ' + (unset ? 'jt-unset' : 'jt-set') + '">' +
                        (unset ? '未設定' : '設定済') + '</span>' +
                        '</td>' +
                        '</tr>'
                    );
                    $tbody.append($tr);
                });

                $('#jt-table').show();
            });
        }

        // ラジオ変更 → 即時保存
        $(document).on('change', '#jt-tbody input[type="radio"]', function () {
            var $radio = $(this);
            var jobName = $radio.closest('tr').data('name');
            var category = $radio.val();
            var $msg = $('#jt-message');

            $.post(amData.ajaxUrl, {
                action: 'am_jobtype_save',
                nonce: amData.nonce,
                job_type_name: jobName,
                category: category,
            }, function (res) {
                if (res.success) {
                    // ステータスバッジを更新
                    var $span = $radio.closest('tr').find('.jt-status');
                    $span.text('設定済').removeClass('jt-unset').addClass('jt-set');
                    $msg.text('「' + jobName + '」を保存しました')
                        .css({ color: '#2c5f2e', background: '#f0fff0', borderLeft: '4px solid #2c5f2e', padding: '8px 20px' })
                        .show();
                } else {
                    $msg.text('保存に失敗しました：' + (res.data.message || ''))
                        .css({ color: '#7a1a1a', background: '#fff0f0', borderLeft: '4px solid #d63638', padding: '8px 20px' })
                        .show();
                }
                setTimeout(function () { $msg.fadeOut(); }, 3000);
            });
        });

        jtLoad();
    }

    /* ================================================================
       集計一覧ページ
       ================================================================ */
    if (currentPage === 'attendance-manager-summary') {

        var slEscHtml = function (str) {
            return $('<span>').text(str == null ? '' : String(str)).html();
        };

        var slLoad = function () {
            var month = $('#am-sl-month').val();
            if (!month) return;

            $('#am-sl-loading').show();
            $('#am-sl-error').hide();
            $('#am-sl-table-wrap').hide();

            $.post(amData.ajaxUrl, {
                action: 'am_summary_list_get',
                nonce: amData.nonce,
                year_month: month
            }, function (res) {
                $('#am-sl-loading').hide();
                $('#am-sl-table-wrap').show();

                if (!res.success) {
                    $('#am-sl-error').text(res.data ? res.data.message : '読み込みに失敗しました').show();
                    return;
                }

                var rows = res.data;
                if (!rows.length) {
                    $('#am-sl-tbody').html('<tr><td colspan="11" style="text-align:center;color:#999;padding:30px;">対象月のデータがありません</td></tr>');
                    return;
                }

                var html = '';
                $.each(rows, function (_, r) {
                    var paidConsumed  = r.paid_has_data
                        ? parseFloat(r.paid_consumed).toFixed(1) + '<span class="am-ms-unit">日</span>'
                        : '<span class="am-ms-na">―</span>';
                    var paidRemaining = r.paid_has_data
                        ? parseFloat(r.paid_remaining).toFixed(1) + '<span class="am-ms-unit">日</span>'
                        : '<span class="am-ms-na">―</span>';
                    var hayataiHtml   = r.hayatai_str
                        ? slEscHtml(r.hayatai_str)
                        : '<span class="am-ms-na">―</span>';

                    html += '<tr>';
                    html += '<td class="am-sl-code">' + slEscHtml(r.employee_code) + '</td>';
                    html += '<td class="am-sl-name">' + slEscHtml(r.name) + '</td>';
                    html += '<td class="am-sl-num">' + r.attendance + '<span class="am-ms-unit">日</span></td>';
                    html += '<td class="am-sl-num' + (r.absent > 0 ? ' am-ms-alert' : '') + '">' + r.absent + '<span class="am-ms-unit">日</span></td>';
                    html += '<td class="am-sl-num' + (r.holiday_work > 0 ? ' am-ms-warn' : '') + '">' + r.holiday_work + '<span class="am-ms-unit">日</span></td>';
                    html += '<td class="am-sl-num' + (r.unmatched_houtei_days > 0 ? ' am-ms-warn' : '') + '">' + r.unmatched_houtei_days + '<span class="am-ms-unit">日</span> / ' + slEscHtml(r.unmatched_houtei_labor_str) + '</td>';
                    html += '<td class="am-sl-num">' + paidConsumed + '</td>';
                    html += '<td class="am-sl-num">' + paidRemaining + '</td>';
                    html += '<td class="am-sl-num">' + slEscHtml(r.labor_str) + '</td>';
                    html += '<td class="am-sl-num' + (r.hayatai_min > 0 ? ' am-ms-warn' : '') + '">' + hayataiHtml + '</td>';
                    html += '<td class="am-sl-num' + (r.overtime_min > 0 ? ' am-ms-over' : '') + '">' + slEscHtml(r.overtime_str) + '</td>';
                    html += '</tr>';
                });

                $('#am-sl-tbody').html(html);
            }).fail(function () {
                $('#am-sl-loading').hide();
                $('#am-sl-table-wrap').show();
                $('#am-sl-error').text('通信エラーが発生しました').show();
            });
        };

        $(document).on('click', '#am-sl-load', slLoad);
    }

    /* ================================================================
       拘束時間CSV取込ページ
       ================================================================ */
    if (currentPage === 'attendance-manager-kousoku-import') {
        $('#am-kousoku-import-form').on('submit', function (event) {
            var overwrite = $('#am-overwrite-existing').is(':checked');
            if (overwrite && !window.confirm('既存行をCSVの値で上書きします。実行してよろしいですか？')) {
                event.preventDefault();
                return;
            }

            $('#am-kousoku-import-submit')
                .prop('disabled', true)
                .text('取込処理中...');
        });
    }

})(jQuery);
