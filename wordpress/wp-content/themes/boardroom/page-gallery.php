<?php get_header(); ?>
<div class="page-hero gallery-hero"><?php echo br_picture('gallery/boardroom-11.jpeg', br_gallery_alt('boardroom-11.jpeg')); ?><h1>Photo Gallery</h1></div>
<div class="wrap"><div class="content wide"><div class="gallery gallery-page">
<?php foreach (br_gallery() as $file) {
    printf(
        '<a href="%s"><img src="%s" alt="%s" loading="lazy"></a>',
        esc_url(br_gallery_src($file)),
        esc_url(br_gallery_src($file, true)),
        esc_attr(br_gallery_alt($file))
    );
} ?>
</div>
</div></div>
<?php echo br_ask_walter_card(home_url('/contact/')); ?>
<section class="block alt" id="amenities"><div class="wrap">
<h2>Amenities</h2>
<?php echo br_amenities_html(); ?>
</div></section>
<?php get_footer();
