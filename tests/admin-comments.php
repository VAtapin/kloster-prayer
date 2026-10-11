<?php
declare(strict_types=1);
require __DIR__.'/admin-render.php';
$row=$wpdb->row();
$row->note="Первая строка\nВторая <script>alert(1)</script> & строка";
$table=new GeorgKlosterOrthodoxDonate\Orders_Table();
$html=$table->column_default($row,'names');
assert(strpos($html,'gkod-order-comment')>strpos($html,'Иоанна'));
assert(str_contains($html,'Первая строка<br'));
assert(str_contains($html,'&lt;script&gt;'));
assert(!str_contains($html,'<script>'));
assert(str_contains($html,'Вторая'));
$row->archived_at='2026-10-10 12:00:00';
assert($table->column_default($row,'names')===$html);
assert(GeorgKlosterOrthodoxDonate\Orders_Table::comment_html('   ')==='');
class CommentFixtureDB extends FixtureDB {
    public function row(){ $row=parent::row();$row->note="Первая строка\nВторая <script>alert(1)</script> & строка";return $row; }
}
$wpdb=new CommentFixtureDB();$_GET=['view'=>1];$_POST=[];$_REQUEST=$_GET;
ob_start();GeorgKlosterOrthodoxDonate\Admin::orders();$detail=ob_get_clean();
assert(str_contains($detail,'gkod-order-comment'));
assert(str_contains($detail,'Первая строка<br'));
assert(!str_contains($detail,'<script>'));
echo "Admin comments: list, archive, detail, multiline and escaping OK\n";
