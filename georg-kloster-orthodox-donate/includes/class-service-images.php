<?php
declare(strict_types=1);
namespace KlosterPrayer;
final class Service_Images
{
    public static function bundled(string $slug): string
    {
        $path='assets/img/services/'.sanitize_file_name($slug).'.webp';
        return is_file(DIR.'/'.$path)?plugins_url($path,FILE):'';
    }
    public static function url(object $service, string $size='medium_large'): string
    {
        if(!empty($service->image_id)){
            $url=wp_get_attachment_image_url((int)$service->image_id,$size);
            if($url)return $url;
        }
        return self::bundled((string)($service->slug??''));
    }
    public static function preview(?object $service): void
    {
        $service??=(object)['slug'=>''];$fallback=self::bundled((string)$service->slug);
        $thumb=self::url($service,'medium');$view=self::url($service);
        echo '<div id="klp-image-preview" data-default="'.esc_url($fallback).'">';
        if($thumb)echo '<a href="'.esc_url($view).('" target="_blank" rel="noopener noreferrer" aria-label="'.esc_attr__('Open background image', 'georg-kloster-orthodox-donate').'"><img src="').esc_url($thumb).('" alt="'.esc_attr__('Service background image', 'georg-kloster-orthodox-donate').'" width="240" decoding="async"><span>'.esc_html__('View image ↗', 'georg-kloster-orthodox-donate').'</span></a>');
        else echo ('<span class="description">'.esc_html__('No image selected.', 'georg-kloster-orthodox-donate').'</span>');
        echo '</div>';
    }
}
