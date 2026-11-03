<?php get_header(); ?>
<div class="page-hero"><?php echo br_picture('gallery/boardroom-01.jpeg', br_gallery_alt('boardroom-01.jpeg')); ?><h1>Photo Gallery</h1></div>
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
<p class="gallery-more"><a href="<?php echo esc_url(home_url('/#amenities')); ?>">See the amenities</a></p>
</div></div>
<?php get_footer();
