<?php
declare(strict_types=1);
namespace KlosterPrayer;

final class Order_Workflow
{
    public static function log(int $id, string $type, string $message): void
    {
        global $wpdb;
        $wpdb->insert(Database::table('order_history'), [
            'order_id'=>$id, 'event_type'=>$type, 'message'=>$message,
            'user_id'=>get_current_user_id(), 'created_at'=>current_time('mysql'),
        ]);
    }

    public static function pdf_generated(array $cards): void
    {
        global $wpdb;
        foreach (array_unique(array_column($cards, 'order_id')) as $id) {
            $id=(int)$id;
            if ($id < 1) continue;
            if ($wpdb->update(Database::table('orders'), ['printed_at'=>current_time('mysql')], ['id'=>$id]) === false) {
                wp_die((esc_html__('The PDF is ready, but the print status could not be saved. Please try again.', 'georg-kloster-orthodox-donate')));
            }
            self::log($id,'pdf_printed',(__('PDF generated; the printed status was set automatically.', 'georg-kloster-orthodox-donate')));
        }
    }

    public static function period(string $start, int $days): array
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        if (!$date || $date->format('Y-m-d') !== $start || $days < 1 || $days > 36500) {
            throw new \InvalidArgumentException((esc_html__('Enter a valid date and a duration between 1 and 36500 days.', 'georg-kloster-orthodox-donate')));
        }
        return [$start, $date->modify('+'.($days-1).' days')->format('Y-m-d')];
    }

    public static function status(?string $start, ?string $end, string $today): string
    {
        if (!$start || !$end) return (__('Not assigned', 'georg-kloster-orthodox-donate'));
        if ($today < $start) return (__('Starts', 'georg-kloster-orthodox-donate').' ').$start;
        if ($today > $end) return (__('Completed', 'georg-kloster-orthodox-donate'));
        $left = (new \DateTimeImmutable($today))->diff(new \DateTimeImmutable($end))->days + 1;
        return (__('In progress · days remaining:', 'georg-kloster-orthodox-donate').' ').$left;
    }

    /** Called only after Admin has checked capability and nonce. */
    public static function save_period(): void
    {
        global $wpdb;
        $id=absint(Input::post('order_id')??0); $item=absint(Input::post('item_id')??0);
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d AND order_id=%d', Database::table('order_items'),$item,$id));
        if (!$row) wp_die((esc_html__('Service not found.', 'georg-kloster-orthodox-donate')));
        $order=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', Database::table('orders'),$id));
        if (!$order || !empty($order->archived_at)) wp_die((esc_html__('Restore the request from the archive first.', 'georg-kloster-orthodox-donate')));
        if (!empty(Input::post('clear_period'))) {
            $start=$end=null;
        } else {
            try { [$start,$end]=self::period(sanitize_text_field(Input::post('start')??''), (int)(Input::post('days')??0)); }
            catch (\InvalidArgumentException $e) { wp_die(esc_html($e->getMessage())); }
        }
        if ($wpdb->update(Database::table('order_items'),['remembrance_start'=>$start,'remembrance_end'=>$end],['id'=>$item,'order_id'=>$id]) === false) wp_die((esc_html__('Could not save the period.', 'georg-kloster-orthodox-donate')));
        self::log($id,'period', $row->service_title.': '.($row->remembrance_start??'—').' — '.($row->remembrance_end??'—').' → '.($start?$start.' — '.$end:(__('period removed', 'georg-kloster-orthodox-donate'))));
    }

    public static function change(int $id, string $action): void
    {
        global $wpdb;
        $order=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', Database::table('orders'),$id));
        if (!$order) return;
        $archive=in_array($action,['delete_order','archive_order'],true);
        $restore=$action==='restore_order';
        if ($archive || $restore) {
            if ($archive === !empty($order->archived_at)) return;
            $data=['archived_at'=>$archive?current_time('mysql'):null];
            $message=$archive?(__('Moved to archive', 'georg-kloster-orthodox-donate')):(__('Restored from archive', 'georg-kloster-orthodox-donate'));
        } else {
            $data=['printed_at'=>$action==='mark_printed'?current_time('mysql'):null];
            $message=$action==='mark_printed'?(__('Staff member confirmed printing', 'georg-kloster-orthodox-donate')):(__('Print status cleared', 'georg-kloster-orthodox-donate'));
        }
        if ($wpdb->update(Database::table('orders'),$data,['id'=>$id]) === false) wp_die((esc_html__('Could not save the change.', 'georg-kloster-orthodox-donate')));
        self::log($id,$action,$message);
    }

    public static function detail(object $order): void
    {
        global $wpdb;
        $id=(int)$order->id;
        echo ('<div class="postbox klp-card"><h2>'.esc_html__('Printing and archive', 'georg-kloster-orthodox-donate').'</h2><p>'.esc_html__('Last PDF generation / print:', 'georg-kloster-orthodox-donate').' ').esc_html($order->printed_at??'—').('. '.esc_html__('Archive', 'georg-kloster-orthodox-donate').': ').esc_html($order->archived_at??(__('None', 'georg-kloster-orthodox-donate'))).'.</p><form method="post">';
        wp_nonce_field('gkod_admin_action','_gkod_nonce');
        echo '<input type="hidden" name="order_ids[]" value="'.(int)$id.'">';
        foreach(['mark_unprinted'=>(__('Clear print status', 'georg-kloster-orthodox-donate')),empty($order->archived_at)?'archive_order':'restore_order'=>empty($order->archived_at)?(__('Archive request', 'georg-kloster-orthodox-donate')):(__('Restore', 'georg-kloster-orthodox-donate'))] as $action=>$label) echo '<button class="button" name="gkod_action" value="'.esc_attr($action).'">'.esc_html($label).'</button> ';
        echo ('</form></div><div class="postbox klp-card"><h2>'.esc_html__('Request history', 'georg-kloster-orthodox-donate').'</h2><p>'.esc_html__('For older requests, available dates are shown; the detailed log starts after the update.', 'georg-kloster-orthodox-donate').'</p><table class="widefat striped"><thead><tr><th>'.esc_html__('Date', 'georg-kloster-orthodox-donate').'</th><th>'.esc_html__('Event', 'georg-kloster-orthodox-donate').'</th><th>'.esc_html__('Staff member', 'georg-kloster-orthodox-donate').'</th></tr></thead><tbody>');
        foreach(['created_at'=>(__('Request received', 'georg-kloster-orthodox-donate')),'paid_at'=>(__('Payment confirmed', 'georg-kloster-orthodox-donate')),'confirmation_sent_at'=>(__('Last confirmation sent', 'georg-kloster-orthodox-donate')),'printed_at'=>(__('Last print status', 'georg-kloster-orthodox-donate'))] as $field=>$label) if(!empty($order->$field)) echo '<tr><td>'.esc_html($order->$field).'</td><td>'.esc_html($label).'</td><td>—</td></tr>';
        $events=$wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE order_id=%d ORDER BY id DESC', Database::table('order_history'),$id));
        foreach($events as $event) {
            $user=!empty($event->user_id)?get_userdata((int)$event->user_id):false;
            echo '<tr><td>'.esc_html($event->created_at).'</td><td>'.esc_html($event->message??'').'</td><td>'.esc_html($user?$user->display_name:(__('System', 'georg-kloster-orthodox-donate'))).'</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function calendar(): void
    {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $date=sanitize_text_field(Input::get('date')??wp_date('Y-m-d'));
        try { self::period($date,1); } catch (\InvalidArgumentException $e) { $date=wp_date('Y-m-d'); }
        $mode=in_array(Input::get('period')??'', ['active','ending','future','finished','unset'],true)?Input::get('period'):'active';
        $clauses=['active'=>'i.remembrance_start<=%s AND i.remembrance_end>=%s','ending'=>'i.remembrance_end=%s','future'=>'i.remembrance_start>%s','finished'=>'i.remembrance_end<%s','unset'=>'i.remembrance_start IS NULL'];
        $args=$mode==='active'?[$date,$date]:($mode==='unset'?[]:[$date]);
        $page=max(1,absint(Input::get('paged')??1));
        $sql='SELECT i.*,o.names,o.intention,o.payment_status FROM %i i JOIN %i o ON o.id=i.order_id WHERE o.archived_at IS NULL AND o.payment_status=\'paid\' AND '.$clauses[$mode].' ORDER BY i.remembrance_end,i.id LIMIT 51 OFFSET %d';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Clause selected from fixed whitelist; identifiers, dates and offset use placeholders.
        $rows=$wpdb->get_results($wpdb->prepare($sql,...array_merge([Database::table('order_items'),Database::table('orders')],$args,[($page-1)*50])));
        echo ('<div class="wrap klp-admin"><h1>'.esc_html__('Commemoration', 'georg-kloster-orthodox-donate').'</h1><p>'.esc_html__('Paid requests outside the archive. Expired periods do not automatically archive requests.', 'georg-kloster-orthodox-donate').'</p><form method="get"><input type="hidden" name="page" value="georg-kloster-orthodox-donate-calendar"><input type="date" name="date" value="').esc_attr($date).'"> <select name="period">';
        foreach(['active'=>(__('Read on this date', 'georg-kloster-orthodox-donate')),'ending'=>(__('Ending on this date', 'georg-kloster-orthodox-donate')),'future'=>(__('Upcoming', 'georg-kloster-orthodox-donate')),'finished'=>(__('Finished', 'georg-kloster-orthodox-donate')),'unset'=>(__('No period', 'georg-kloster-orthodox-donate'))] as $key=>$label) echo '<option value="'.esc_attr($key).'" '.selected($mode,$key,false).'>'.esc_html($label).'</option>';
        echo ('</select> <button class="button">'.esc_html__('Show', 'georg-kloster-orthodox-donate').'</button></form><p></p><table class="widefat striped"><thead><tr><th>'.esc_html__('Request / service', 'georg-kloster-orthodox-donate').'</th><th>'.esc_html__('Type', 'georg-kloster-orthodox-donate').'</th><th>'.esc_html__('Names', 'georg-kloster-orthodox-donate').'</th><th>'.esc_html__('Start', 'georg-kloster-orthodox-donate').'</th><th>'.esc_html__('End', 'georg-kloster-orthodox-donate').'</th><th>'.esc_html__('Status on the selected date', 'georg-kloster-orthodox-donate').'</th></tr></thead><tbody>');
        foreach(array_slice($rows,0,50) as $row) echo '<tr><td><a href="'.esc_url(admin_url('admin.php?page=georg-kloster-orthodox-donate&view='.(int)$row->order_id)).'">#'.(int)$row->order_id.' · '.esc_html($row->service_title).'</a></td><td>'.($row->intention==='rest'?(__('For the departed', 'georg-kloster-orthodox-donate')):(__('For the living', 'georg-kloster-orthodox-donate'))).'</td><td>'.nl2br(esc_html($row->names)).'</td><td>'.esc_html($row->remembrance_start??'—').'</td><td>'.esc_html($row->remembrance_end??'—').'</td><td>'.esc_html(self::status($row->remembrance_start??null,$row->remembrance_end??null,$date)).'</td></tr>';
        if(!$rows) echo ('<tr><td colspan="6">'.esc_html__('No requests.', 'georg-kloster-orthodox-donate').'</td></tr>');
        echo '</tbody></table><p>';
        foreach([$page-1=>(__('← Previous', 'georg-kloster-orthodox-donate')),$page+1=>(__('Next →', 'georg-kloster-orthodox-donate'))] as $target=>$label) if($target>0&&($target<$page||count($rows)>50)) echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>'georg-kloster-orthodox-donate-calendar','date'=>$date,'period'=>$mode,'paged'=>$target],admin_url('admin.php'))).'">'.esc_html($label).'</a> ';
        echo '</p></div>';
    }
}
