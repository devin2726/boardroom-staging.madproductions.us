<?php get_header(); ?>
<div class="page-hero" style="background-image:url(<?php echo esc_url(br_gallery_src('boardroom-01.jpeg')); ?>)"><h1>Photo Gallery</h1></div>
<div class="wrap"><div class="content wide"><div class="gallery gallery-page">
<?php foreach (br_gallery() as $file) {
    printf(
        '<a href="%s"><img src="%s" alt="The Boardroom" loading="lazy"></a>',
        esc_url(br_gallery_src($file)),
        esc_url(br_gallery_src($file, true))
    );
} ?>
</div></div></div>
<?php get_footer();
