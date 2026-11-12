/**
 * MediaPickerModal strings (Figma 294:2661 / 303:55922) and the product images panel's
 * "Choose from library" entry, spread into `en` / `bn` in messages.ts. The library
 * screen's own strings (tiles, alt chips, queue rows) live in messages.media.ts.
 *
 * NEEDS NATIVE-SPEAKER REVIEW, like the rest of the Bangla UI copy. Digits stay Western.
 */
export const mediaPickerEn = {
    'picker.dialog': 'Choose media',
    'picker.close': 'Close',
    'picker.tabs': 'Media source',
    'picker.tab.library': 'Library',
    'picker.tab.upload': 'Upload',

    'picker.product.title': 'Choose product images',
    'picker.product.subtitle': '{product} · gallery has {count} of {max} images',
    'picker.product.hint': 'Product images: square 1:1, at least 1200 × 1200. JPG, PNG, WebP or AVIF, up to 10 MB. Both English and বাংলা alt text are needed before the product can be published.',
    'picker.product.uploadHint': 'JPG, PNG, WebP or AVIF · max 10 MB each · square 1:1, min 1200 × 1200 · uploads go to {folder}',
    'picker.product.open': 'Choose from library',
    'picker.product.attachFailed': '{name} was not added: {reason}',
    'picker.product.attached': '{count} image(s) added to the gallery',

    'picker.folders': 'Folders',
    'picker.search': 'Search media',
    'picker.searchIn': 'Search {count} files in {folder}',
    'picker.grid': 'Library files',
    'picker.showing': 'Showing {shown} of {total}',
    'picker.empty': 'No files here yet. Upload some on the Upload tab.',
    'picker.noResults': 'No files match “{search}”.',
    'picker.clearSearch': 'Clear search',

    'picker.flag.crop': 'Not 1:1 — will crop',
    'picker.flag.small': 'Below {min} px',
    'picker.flag.processing': 'Still processing',
    'picker.pick': '{name}, {meta}',
    'picker.pickOrdered': '{name}, {meta}, pick {n}',

    'picker.altWarningOne': '1 selected image has no বাংলা alt text. You can insert it now, but the product stays unpublished until it is added.',
    'picker.altWarningMany': '{count} selected images have no বাংলা alt text. You can insert them now, but the product stays unpublished until they are added.',
    'picker.uploadAltWarning': '{count} new image(s) need alt text in English and বাংলা before the product can be published.',
    'picker.addAlt': 'Add alt text',

    'picker.footer.ordered': '{count} selected · numbers set the gallery order, 1 is the primary image',
    'picker.footer.single': '{count} selected',
    'picker.footer.full': '{count} selected · the gallery has room for {max} more',
    'picker.footer.upload': '{ready} ready to insert · {failed} failed are not inserted',
    'picker.footer.uploadNoFailed': '{ready} ready to insert',
    'picker.maxReached': 'The gallery has room for {max} more. Deselect an image to pick another.',
    'picker.insert': 'Insert',
    'picker.insertOne': 'Insert 1 image',
    'picker.insertMany': 'Insert {count} images',
    'picker.inserting': 'Inserting…',

    'picker.drop': 'Drop images here or browse',
    'picker.dropActive': 'Drop to upload',
    'picker.browse': 'Browse files',
    'picker.uploadQueue': 'Uploads',
};

export const mediaPickerBn: Record<keyof typeof mediaPickerEn, string> = {
    'picker.dialog': 'মিডিয়া বেছে নিন',
    'picker.close': 'বন্ধ করুন',
    'picker.tabs': 'মিডিয়ার উৎস',
    'picker.tab.library': 'লাইব্রেরি',
    'picker.tab.upload': 'আপলোড',

    'picker.product.title': 'পণ্যের ছবি বেছে নিন',
    'picker.product.subtitle': '{product} · গ্যালারিতে {max}টির মধ্যে {count}টি ছবি আছে',
    'picker.product.hint': 'পণ্যের ছবি: বর্গাকার 1:1, অন্তত 1200 × 1200। JPG, PNG, WebP বা AVIF, সর্বোচ্চ 10 MB। পণ্য প্রকাশের আগে ইংরেজি ও বাংলা দুই alt text-ই লাগবে।',
    'picker.product.uploadHint': 'JPG, PNG, WebP বা AVIF · প্রতিটি সর্বোচ্চ 10 MB · বর্গাকার 1:1, ন্যূনতম 1200 × 1200 · আপলোড যাবে {folder} ফোল্ডারে',
    'picker.product.open': 'লাইব্রেরি থেকে বেছে নিন',
    'picker.product.attachFailed': '{name} যোগ করা যায়নি: {reason}',
    'picker.product.attached': 'গ্যালারিতে {count}টি ছবি যোগ হয়েছে',

    'picker.folders': 'ফোল্ডার',
    'picker.search': 'মিডিয়া খুঁজুন',
    'picker.searchIn': '{folder}-এর {count}টি ফাইলে খুঁজুন',
    'picker.grid': 'লাইব্রেরির ফাইল',
    'picker.showing': '{total}টির মধ্যে {shown}টি দেখানো হচ্ছে',
    'picker.empty': 'এখানে এখনও কোনো ফাইল নেই। আপলোড ট্যাবে আপলোড করুন।',
    'picker.noResults': '“{search}”-এর সাথে কোনো ফাইল মেলেনি।',
    'picker.clearSearch': 'খোঁজা মুছুন',

    'picker.flag.crop': '1:1 নয় — ক্রপ হবে',
    'picker.flag.small': '{min} px-এর কম',
    'picker.flag.processing': 'এখনও প্রসেস হচ্ছে',
    'picker.pick': '{name}, {meta}',
    'picker.pickOrdered': '{name}, {meta}, বাছাই {n}',

    'picker.altWarningOne': 'বাছাই করা 1টি ছবির বাংলা alt text নেই। এখনই যোগ করতে পারেন, তবে alt text না দেওয়া পর্যন্ত পণ্যটি অপ্রকাশিত থাকবে।',
    'picker.altWarningMany': 'বাছাই করা {count}টি ছবির বাংলা alt text নেই। এখনই যোগ করতে পারেন, তবে alt text না দেওয়া পর্যন্ত পণ্যটি অপ্রকাশিত থাকবে।',
    'picker.uploadAltWarning': 'পণ্য প্রকাশের আগে নতুন {count}টি ছবিতে ইংরেজি ও বাংলা alt text লাগবে।',
    'picker.addAlt': 'Alt text যোগ করুন',

    'picker.footer.ordered': '{count}টি বাছাই · নম্বর গ্যালারির ক্রম ঠিক করে, 1 হলো প্রধান ছবি',
    'picker.footer.single': '{count}টি বাছাই',
    'picker.footer.full': '{count}টি বাছাই · গ্যালারিতে আর {max}টির জায়গা আছে',
    'picker.footer.upload': '{ready}টি যোগের জন্য প্রস্তুত · ব্যর্থ {failed}টি যোগ হবে না',
    'picker.footer.uploadNoFailed': '{ready}টি যোগের জন্য প্রস্তুত',
    'picker.maxReached': 'গ্যালারিতে আর {max}টির জায়গা আছে। অন্যটি বাছতে একটি ছবি বাদ দিন।',
    'picker.insert': 'যোগ করুন',
    'picker.insertOne': '1টি ছবি যোগ করুন',
    'picker.insertMany': '{count}টি ছবি যোগ করুন',
    'picker.inserting': 'যোগ হচ্ছে…',

    'picker.drop': 'এখানে ছবি ছাড়ুন বা ব্রাউজ করুন',
    'picker.dropActive': 'আপলোড করতে ছাড়ুন',
    'picker.browse': 'ফাইল ব্রাউজ করুন',
    'picker.uploadQueue': 'আপলোড',
};
