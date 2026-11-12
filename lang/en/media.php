<?php

declare(strict_types=1);

// Media library messages returned by the admin API (docs/api/media-library.md). Keys mirror lang/bn/media.php.
return [

    'file' => [
        'empty' => 'The file is empty.',
        'wrong_type' => 'Not a real image or PDF — the content is not JPG, PNG, WebP, AVIF or PDF.',
        'too_large' => ':size MB — over the :limit MB limit.',
        'too_wide' => ':width × :height px — over the :limit px limit per side.',
        'payload' => 'The file contains embedded script or markup and was refused.',
        'unreadable' => 'The image could not be read.',
        'undecodable' => 'The image could not be read.',
        'active_content' => 'The PDF contains scripts, attachments or interactive content. Export it again as a plain PDF.',
        'replace_image' => 'Replace with an image: this file is an image.',
        'replace_document' => 'Replace with a PDF: this file is a PDF.',
    ],

    'in_use' => 'This file is used in :count places. Remove it from them, or replace the file instead.',
    'in_use_one' => 'This file is used in 1 place. Remove it from there, or replace the file instead.',

    'folder' => [
        'system_locked' => 'System folders cannot be renamed or deleted.',
        'name_taken' => 'A folder with this name already exists.',
    ],

    'zip' => [
        'too_many' => ':max files at most per download; you selected :count.',
        'too_large' => 'The selected files are :size MB; the limit is :limit MB per download.',
    ],

    'attach' => [
        'not_image' => 'Only an image can be added to the gallery.',
        'already_attached' => 'This image is already in the product gallery.',
        'file_or_media' => 'Send either a file or a media_id, not both.',
        'crop_with_media' => 'A library image cannot be cropped while it is added. Add it, then crop.',
    ],

];
