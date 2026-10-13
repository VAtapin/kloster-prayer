<?php
declare(strict_types=1);

namespace GeorgKlosterOrthodoxDonate;

use Dompdf\Dompdf;
use Dompdf\Options;

final class Pdf
{
    public static function init(): void
    {
        add_action('admin_post_gkod_pdf', [self::class, 'download']);
    }

    public static function url(array $filters = []): string
    {
        $args = ['action' => 'gkod_pdf', '_wpnonce' => wp_create_nonce('gkod_pdf')];
        foreach (['archive','payment_status','intention','printed','blank','language'] as $key) if (!empty($filters[$key])) $args[$key] = sanitize_key((string) $filters[$key]);
        if (!empty($filters['order_ids'])) $args['order_ids'] = implode(',', array_filter(array_map('absint', is_array($filters['order_ids']) ? $filters['order_ids'] : explode(',', (string) $filters['order_ids']))));
        return add_query_arg($args, admin_url('admin-post.php'));
    }

    public static function download(): never
    {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('gkod_pdf');
        if (!class_exists(Dompdf::class)) require_once DIR . '/includes/lib/dompdf/autoload.inc.php';
        $cards = !empty(Input::get('blank')) ? [['intention' => (Input::get('intention') ?? '') === 'rest' ? 'rest' : 'health', 'language' => (Input::get('language') ?? '') === 'de' ? 'de' : 'ru', 'title' => '', 'names' => [], 'note' => '']] : self::cards();
        if (!$cards) wp_die((esc_html__('No requests match the selected filter.', 'georg-kloster-orthodox-donate')));
        $options = new Options(); $options->set('isRemoteEnabled', false); $options->set('isHtml5ParserEnabled', true); $options->set('defaultFont', 'DejaVu Sans'); $options->setChroot(DIR);
        $dompdf = new Dompdf($options); $dompdf->setPaper('A4', 'landscape'); $dompdf->loadHtml(self::modern_html($cards), 'UTF-8'); $dompdf->render();
        // Serialize successfully before changing print state. Rendering/output errors leave it unchanged.
        $output = $dompdf->output();
        if (!str_starts_with($output, '%PDF-')) wp_die((esc_html__('Could not generate the PDF.', 'georg-kloster-orthodox-donate')));
        if (headers_sent()) wp_die((esc_html__('Could not send the PDF: headers have already been sent.', 'georg-kloster-orthodox-donate')));
        Order_Workflow::pdf_generated($cards);
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="zapiski-'.wp_date('Ymd-His').'.pdf"');
        header('Content-Length: '.strlen($output));
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Dompdf-generated binary PDF, validated above; HTML escaping corrupts the file.
        echo $output; exit;
    }

    private static function cards(): array
    {
        global $wpdb; $where=["o.intention<>'none'"]; $args=[];
        $status=sanitize_key((string)(Input::get('payment_status')??'')); $intention=sanitize_key((string)(Input::get('intention')??'')); $printed=sanitize_key((string)(Input::get('printed')??''));
        $ids=array_filter(array_map('absint',explode(',',(string)(Input::get('order_ids')??''))));
        if($ids){$where[]='o.id IN ('.implode(',',array_fill(0,count($ids),'%d')).')';array_push($args,...$ids);}
        // Explicit selections may include archived orders. Only whole-list exports
        // use the archive view, so downloading never requires restoring an order.
        else $where[]=(Input::get('archive')??'')==='yes'?'o.archived_at IS NOT NULL':'o.archived_at IS NULL';
        if(in_array($status,['pending','paid','failed','refunded','cancelled'],true)){ $where[]='o.payment_status=%s';$args[]=$status; }
        if(in_array($intention,['health','rest'],true)){ $where[]='o.intention=%s';$args[]=$intention; }
        if($printed==='yes')$where[]='o.printed_at IS NOT NULL';elseif($printed==='no')$where[]='o.printed_at IS NULL';
        $sql='SELECT o.*,i.service_title,i.service_id,s.slug AS service_slug FROM %i o INNER JOIN %i i ON i.order_id=o.id LEFT JOIN %i s ON s.id=i.service_id WHERE '.implode(' AND ',$where).' ORDER BY o.intention,o.created_at';
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Fixed filter clauses only; all identifiers and user values use placeholders. Custom prayer/print ledger; export must read current selected rows and service configuration.
        $rows=$wpdb->get_results($wpdb->prepare($sql,...array_merge([Database::table('orders'),Database::table('order_items'),Database::table('services')],$args))); $cards=[];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom prayer/print ledger; export must read current selected rows and service configuration.
        foreach($rows as $row){ $limit=(int)$wpdb->get_var($wpdb->prepare('SELECT names_per_sheet FROM %i WHERE id=%d', Database::table('services'),(int)$row->service_id))?:10; $names=Payments::split_names((string)$row->names); foreach(array_chunk($names,$limit) as $chunk)$cards[]=['order_date'=>self::order_date($row),'order_id'=>(int)$row->id,'printed_at'=>$row->printed_at,'intention'=>$row->intention,'language'=>$row->language==='ru'?'ru':'de','title'=>$row->service_title,'names'=>$chunk,'note'=>$row->note]; }
        return $cards;
    }

    private static function order_date(object $row): string
    {
        if (($row->service_slug ?? '') !== 'sorokoust' && !preg_match('/^(сорокоуст|sorokoust)(?:\\s|$)/iu', trim($row->service_title ?? ''))) return '';
        $raw = substr((string)($row->created_at ?? ''), 0, 10);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        return $date && $date->format('Y-m-d') === $raw ? $date->format('d.m.Y') : '';
    }

    /** Original supplied artwork: coordinates below use its 3540 x 2480 grid. */
    private static function modern_html(array $cards): string
    {
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:6mm}body{margin:0;font-family:"DejaVu Sans",sans-serif;color:#171717}.page{page-break-inside:avoid;position:relative;width:282mm;height:197.56mm}.page+.page{page-break-before:always}.background{position:absolute;left:0;top:0;width:282mm;height:197.56mm}.card{position:absolute;top:0;width:94mm;height:197.56mm}.service{position:absolute;left:12mm;width:70mm;top:27.4mm;font-size:12pt;font-weight:bold;text-align:center;line-height:5mm}.name{position:absolute;left:13.5mm;width:67mm;line-height:5mm;white-space:nowrap}.kind{position:absolute;left:14mm;top:19.7mm;width:66mm;height:5.1mm;background:white;font-size:11pt;font-weight:bold;line-height:5mm;text-align:center}.order-date{position:absolute;left:13mm;top:186mm;width:68mm;text-align:center;font-size:8pt;line-height:3mm}.note{position:absolute;left:13.5mm;width:67mm;font-size:9pt;line-height:4mm;text-align:left}</style></head><body>';
        $groups = [];
        foreach ($cards as $card) {
            $noteLines = self::note_lines((string) $card['note']);
            $noteParts = $noteLines ? array_chunk($noteLines, 30) : [[]];
            // Reserve measured comment space before splitting the names. The date
            // stays below 186 mm; neither names nor comments may reach it.
            $firstTop = 573 * 282 / 3540 - 5.3;
            $step = 128.55 * 282 / 3540;
            $limit = $noteLines ? max(1, min(14, 1 + (int) floor((180 - $firstTop - 7 - count($noteParts[0]) * 4) / $step))) : 14;
            foreach (array_chunk($card['names'] ?: [''], $limit) as $names) {
                foreach ($noteParts as $part => $lines) {
                    $copy = $card; $copy['names'] = $part === 0 ? $names : [];
                    $copy['note_lines'] = $lines;
                    $groups[$card['language'].'-'.$card['intention']][] = $copy;
                }
            }
        }
        foreach ($groups as $group) foreach (array_chunk($group, 3) as $page) {
            $blank = array_merge($page[0], ['title'=>'', 'names'=>[], 'note'=>'', 'note_lines'=>[], 'order_date'=>'']);
            $page = array_pad($page, 3, $blank);
            $rest = $page[0]['intention'] === 'rest';
            $file = DIR.'/assets/img/'.($rest ? 'UPOKOI-print.png' : 'ZDRAVIE-print.png');
            $html .= '<div class="page"><img class="background" src="data:image/png;base64,'.base64_encode((string)file_get_contents($file)).'">';
            foreach ($page as $index => $card) {
                $html .= '<div class="card '.($rest?'rest':'health').'" style="left:'.($index*94).'mm">';
                // Only translate the heading; the original cross, border and ruled lines stay untouched.
                if ($card['language'] === 'de') $html .= '<div class="kind" style="color:'.($rest?'#595959':'#ad5754').'">'.esc_html(mb_strtoupper(I18n::text($rest?'rest':'health', 'de'))).'</div>';
                $html .= '<div class="service">'.esc_html($card['title']).'</div>';
                foreach ($card['names'] as $line => $name) {
                    $size = mb_strlen($name) > 35 ? 8 : (mb_strlen($name) > 27 ? 9 : 11);
                    // Absolute placement prevents accumulating line-height drift down the page.
                    $top = (573 + $line * 128.55) * 282 / 3540 - 5.3;
                    $html .= '<div class="name" style="top:'.$top.'mm;font-size:'.$size.'pt">'.esc_html($name).'</div>';
                }
                if (!empty($card['order_date'])) $html .= '<div class="order-date">'.esc_html(I18n::text('order_date', $card['language']).' '.$card['order_date']).'</div>';
                if ($card['note_lines']) {
                    $noteTop = (573 + max(0, count($card['names']) - 1) * 128.55) * 282 / 3540 - 5.3 + (count($card['names']) ? 7 : 0);
                    foreach ($card['note_lines'] as $line => $text) {
                        $html .= '<div class="note" style="top:'.($noteTop + $line * 4).'mm;height:4mm;white-space:nowrap">'.esc_html($text).'</div>';
                    }
                }
                $html .= '</div>';
            }
            $html .= '</div>';
        }
        return $html.'</body></html>';
    }

    /** Wrap comments with the same font/size used by the PDF, including long words. */
    private static function note_lines(string $text): array
    {
        if (trim($text) === '') return [];
        if (!class_exists(Dompdf::class)) require_once DIR.'/includes/lib/dompdf/autoload.inc.php';
        static $metrics = null;
        $metrics ??= (new Dompdf())->getFontMetrics();
        $font = $metrics->getFont('DejaVu Sans', 'normal');
        $width = 66 * 72 / 25.4;
        $lines = [];
        foreach (preg_split('/\R/u', trim($text)) ?: [] as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $candidate = $line === '' ? $word : $line.' '.$word;
                if ($metrics->getTextWidth($candidate, $font, 9) <= $width) { $line = $candidate; continue; }
                if ($line !== '') { $lines[] = $line; $line = ''; }
                foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
                    if ($line !== '' && $metrics->getTextWidth($line.$character, $font, 9) > $width) { $lines[] = $line; $line = ''; }
                    $line .= $character;
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }
}
