<?php

class ImageFixer
{

    public function makeImageProper($matches, ?string $prefix = '')
    {
        print_r($matches);
    }


    public function replaceContentAlt($contents) {
        return preg_replace_callback("/(<img[^>]*src *= *[\"']?)([^\"']*)/i", [$this, 'makeImageProper'], $contents);
    }

    public function replaceContent()
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        foreach ($dom->getElementsByTagName('img') as $img) {
            $src = $iframe->getAttribute('src');
            $image = Image::get()->filter(['Name' => $src])->first();
            // if($image) {
        }
        echo $dom->saveHTML();
    }
}
