<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="am-monthly-summary-wrap">
    <div class="am-card-header" style="border-radius:6px 6px 0 0;">
        <span class="dashicons dashicons-chart-area"></span> 月間サマリ
    </div>
    <div style="overflow-x:auto;">
        <table class="am-ms-table">
            <thead>
                <tr>
                    <th>出勤日数</th><th>欠勤日数</th><th>休日出勤日数</th>
                    <th title="振替休がない法定休日出勤の日数と実労働時間">振替なし法定休出勤</th>
                    <th>有給消化日数</th><th>有給残日数</th>
                    <th>労働時間</th><th>早退遅刻時間</th><th>確定残業時間</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="am-ms-num" data-ms="attendance"><?php echo (int) $monthly_summary['attendance']; ?><span class="am-ms-unit">日</span></td>
                    <td class="am-ms-num <?php echo $monthly_summary['absent'] > 0 ? 'am-ms-alert' : ''; ?>" data-ms="absent"><?php echo (int) $monthly_summary['absent']; ?><span class="am-ms-unit">日</span></td>
                    <td class="am-ms-num <?php echo $monthly_summary['holiday_work'] > 0 ? 'am-ms-warn' : ''; ?>" data-ms="holiday_work"><?php echo (int) $monthly_summary['holiday_work']; ?><span class="am-ms-unit">日</span></td>
                    <td class="am-ms-num <?php echo $monthly_summary['unmatched_houtei_days'] > 0 ? 'am-ms-warn' : ''; ?>" data-ms="unmatched_houtei">
                        <?php echo (int) $monthly_summary['unmatched_houtei_days']; ?><span class="am-ms-unit">日</span> /
                        <?php echo esc_html( AM_Compute_Chokyo::format_min( $monthly_summary['unmatched_houtei_labor_min'] ) ); ?>
                    </td>
                    <td class="am-ms-num" data-ms="paid_consumed">
                        <?php if ( $monthly_summary['paid_has_data'] ) : ?>
                            <?php echo number_format( $monthly_summary['paid_consumed'], 1 ); ?><span class="am-ms-unit">日</span>
                        <?php else : ?><span class="am-ms-na">―</span><?php endif; ?>
                    </td>
                    <td class="am-ms-num" data-ms="paid_remaining">
                        <?php if ( $monthly_summary['paid_has_data'] ) : ?>
                            <?php echo number_format( $monthly_summary['paid_remaining'], 1 ); ?><span class="am-ms-unit">日</span>
                        <?php else : ?><span class="am-ms-na">―</span><?php endif; ?>
                    </td>
                    <td class="am-ms-num" data-ms="labor"><?php echo esc_html( AM_Compute_Chokyo::format_min( $monthly_summary['labor_min'] ) ); ?></td>
                    <td class="am-ms-num <?php echo $monthly_summary['hayatai_min'] > 0 ? 'am-ms-warn' : ''; ?>" data-ms="hayatai">
                        <?php echo $monthly_summary['hayatai_min'] > 0 ? esc_html( AM_Compute_Chokyo::format_min( $monthly_summary['hayatai_min'] ) ) : '―'; ?>
                    </td>
                    <td class="am-ms-num <?php echo (int)$monthly_summary['overtime_min'] > 0 ? 'am-ms-over' : ''; ?>" data-ms="overtime"><?php echo esc_html( AM_Compute_Chokyo::format_min( $monthly_summary['overtime_min'] ) ); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
