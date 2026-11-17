/**
 * Analytics › Live (Figma B · 24 premium pass 522:81629 / 522:82129). Spread into `en` / `bn` in messages.ts.
 *
 * NEEDS NATIVE-SPEAKER REVIEW, like the rest of the Bangla UI copy. Digits stay Western (rule 36).
 */
export const analyticsLiveEn = {
    "analyticsLive.tab": "Live",
    "analyticsLive.title": "Live",
    "analyticsLive.subtitle":
        "What is happening in the shop right now. Updates every minute.",
    "analyticsLive.status.live": "Live · updated {time}",
    "analyticsLive.status.paused": "Paused · updated {time}",
    "analyticsLive.pause": "Pause",
    "analyticsLive.resume": "Resume",
    "analyticsLive.loading": "Loading what is happening now…",

    "analyticsLive.plain.label": "In plain words · right now",
    "analyticsLive.plain.shoppers":
        "{active} shoppers are on the shop now; {checkout} of them are at checkout.",
    "analyticsLive.plain.shoppersOne":
        "1 shopper is on the shop now; {checkout} of them at checkout.",
    "analyticsLive.plain.noShoppers":
        "No shoppers who allowed tracking are on the shop right now.",
    "analyticsLive.plain.trackingOff":
        "Shopper counts are off: no tracking is switched on.",
    "analyticsLive.plain.ordersMore":
        "{orders} orders today so far for {value} — {difference} more than this time yesterday.",
    "analyticsLive.plain.ordersFewer":
        "{orders} orders today so far for {value} — {difference} fewer than this time yesterday.",
    "analyticsLive.plain.ordersSame":
        "{orders} orders today so far for {value} — the same as this time yesterday.",
    "analyticsLive.plain.noOrders":
        "No orders yet today — {yesterday} by this time yesterday.",
    "analyticsLive.plain.lastOrder": "The last order came {ago}.",
    "analyticsLive.plain.lastOrderFrom":
        "The last order came {ago} from {district}.",
    "analyticsLive.plain.lastOrderProduct":
        "The last order came {ago} ({product}).",
    "analyticsLive.plain.lastOrderFromProduct":
        "The last order came {ago} from {district} ({product}).",

    "analyticsLive.kpi.now": "On the shop now",
    "analyticsLive.kpi.nowHint": "Active in the last {minutes} minutes",
    "analyticsLive.kpi.checkout": "At checkout",
    "analyticsLive.kpi.checkoutHint": "Started checkout, not ordered yet",
    "analyticsLive.kpi.orders": "Orders today",
    "analyticsLive.kpi.ordersHint": "{value} placed",
    "analyticsLive.kpi.last": "Last order",
    "analyticsLive.kpi.lastValue": "{minutes} min",
    "analyticsLive.kpi.lastNone": "No orders yet",

    "analyticsLive.ago.now": "just now",
    "analyticsLive.ago.minutes": "{minutes} min ago",
    "analyticsLive.ago.hours": "{hours} h ago",
    "analyticsLive.ago.days": "{days} d ago",
    "analyticsLive.ago.plainMinutes": "{minutes} minutes ago",
    "analyticsLive.ago.plainMinute": "1 minute ago",

    "analyticsLive.feed.title": "What is happening",
    "analyticsLive.feed.subtitle":
        "Newest first · shoppers who allowed tracking, and every order",
    "analyticsLive.feed.subtitleOrders":
        "Newest first · orders only (tracking is off)",
    "analyticsLive.feed.empty":
        "Nothing yet. Orders and shopper activity from the last 24 hours show here.",
    "analyticsLive.feed.order": "Order placed",
    "analyticsLive.feed.view_item": "Viewing",
    "analyticsLive.feed.add_to_cart": "Added to cart",
    "analyticsLive.feed.begin_checkout": "Started checkout",
    "analyticsLive.feed.items": "{count} items",
    "analyticsLive.feed.item": "1 item",

    "analyticsLive.viewing.title": "Being viewed now",
    "analyticsLive.viewing.subtitle": "Products · last {minutes} minutes",
    "analyticsLive.viewing.shoppers": "{count} shoppers",
    "analyticsLive.viewing.shopper": "1 shopper",
    "analyticsLive.viewing.empty":
        "Nobody who allowed tracking is looking at a product right now.",
    "analyticsLive.viewing.noPages":
        "Pages are not counted — the shop records product views only.",

    "analyticsLive.hourly.title": "Orders today by hour",
    "analyticsLive.hourly.subtitle": "Dhaka time",
    "analyticsLive.hourly.bar": "{hour}: {orders} orders",

    "analyticsLive.note.consent":
        "Shopper counts, activity and product views cover shoppers who allowed tracking. Orders cover every order.",
    "analyticsLive.note.noGa4":
        "Shoppers are counted by their Google Analytics session, so the counts stay at 0 until Google Analytics 4 is switched on in Settings › Tracking.",
    "analyticsLive.note.off":
        "Tracking is switched off, so only orders show here. Switch on Google Analytics 4 or Meta in Settings › Tracking to see shoppers.",
} as const;

export const analyticsLiveBn: Record<keyof typeof analyticsLiveEn, string> = {
    "analyticsLive.tab": "লাইভ",
    "analyticsLive.title": "লাইভ",
    "analyticsLive.subtitle":
        "এই মুহূর্তে দোকানে কী হচ্ছে। প্রতি মিনিটে হালনাগাদ হয়।",
    "analyticsLive.status.live": "লাইভ · হালনাগাদ {time}",
    "analyticsLive.status.paused": "থামানো · হালনাগাদ {time}",
    "analyticsLive.pause": "থামান",
    "analyticsLive.resume": "আবার চালু করুন",
    "analyticsLive.loading": "এখনকার তথ্য আনা হচ্ছে…",

    "analyticsLive.plain.label": "সহজ কথায় · এখন",
    "analyticsLive.plain.shoppers":
        "এখন দোকানে {active} জন ক্রেতা আছেন; তাঁদের {checkout} জন চেকআউটে।",
    "analyticsLive.plain.shoppersOne":
        "এখন দোকানে 1 জন ক্রেতা আছেন; চেকআউটে {checkout} জন।",
    "analyticsLive.plain.noShoppers":
        "ট্র্যাকিংয়ে সম্মতি দেওয়া কোনো ক্রেতা এখন দোকানে নেই।",
    "analyticsLive.plain.trackingOff":
        "ক্রেতার সংখ্যা বন্ধ: কোনো ট্র্যাকিং চালু নেই।",
    "analyticsLive.plain.ordersMore":
        "আজ এ পর্যন্ত {orders}টি অর্ডার, মোট {value} — গতকাল এই সময়ের চেয়ে {difference}টি বেশি।",
    "analyticsLive.plain.ordersFewer":
        "আজ এ পর্যন্ত {orders}টি অর্ডার, মোট {value} — গতকাল এই সময়ের চেয়ে {difference}টি কম।",
    "analyticsLive.plain.ordersSame":
        "আজ এ পর্যন্ত {orders}টি অর্ডার, মোট {value} — গতকাল এই সময়ের সমান।",
    "analyticsLive.plain.noOrders":
        "আজ এখনো কোনো অর্ডার নেই — গতকাল এই সময়ে ছিল {yesterday}টি।",
    "analyticsLive.plain.lastOrder": "শেষ অর্ডারটি এসেছে {ago}।",
    "analyticsLive.plain.lastOrderFrom":
        "শেষ অর্ডারটি এসেছে {ago}, {district} থেকে।",
    "analyticsLive.plain.lastOrderProduct":
        "শেষ অর্ডারটি এসেছে {ago} ({product})।",
    "analyticsLive.plain.lastOrderFromProduct":
        "শেষ অর্ডারটি এসেছে {ago}, {district} থেকে ({product})।",

    "analyticsLive.kpi.now": "এখন দোকানে",
    "analyticsLive.kpi.nowHint": "গত {minutes} মিনিটে সক্রিয়",
    "analyticsLive.kpi.checkout": "চেকআউটে",
    "analyticsLive.kpi.checkoutHint": "চেকআউট শুরু করেছেন, এখনো অর্ডার করেননি",
    "analyticsLive.kpi.orders": "আজকের অর্ডার",
    "analyticsLive.kpi.ordersHint": "{value} দেওয়া হয়েছে",
    "analyticsLive.kpi.last": "শেষ অর্ডার",
    "analyticsLive.kpi.lastValue": "{minutes} মিনিট",
    "analyticsLive.kpi.lastNone": "এখনো কোনো অর্ডার নেই",

    "analyticsLive.ago.now": "এইমাত্র",
    "analyticsLive.ago.minutes": "{minutes} মিনিট আগে",
    "analyticsLive.ago.hours": "{hours} ঘণ্টা আগে",
    "analyticsLive.ago.days": "{days} দিন আগে",
    "analyticsLive.ago.plainMinutes": "{minutes} মিনিট আগে",
    "analyticsLive.ago.plainMinute": "1 মিনিট আগে",

    "analyticsLive.feed.title": "কী হচ্ছে",
    "analyticsLive.feed.subtitle":
        "নতুনগুলো আগে · ট্র্যাকিংয়ে সম্মতি দেওয়া ক্রেতা, আর সব অর্ডার",
    "analyticsLive.feed.subtitleOrders":
        "নতুনগুলো আগে · শুধু অর্ডার (ট্র্যাকিং বন্ধ)",
    "analyticsLive.feed.empty":
        "এখনো কিছু নেই। গত 24 ঘণ্টার অর্ডার আর ক্রেতাদের কাজ এখানে দেখা যাবে।",
    "analyticsLive.feed.order": "অর্ডার দেওয়া হয়েছে",
    "analyticsLive.feed.view_item": "দেখছেন",
    "analyticsLive.feed.add_to_cart": "কার্টে যোগ করেছেন",
    "analyticsLive.feed.begin_checkout": "চেকআউট শুরু করেছেন",
    "analyticsLive.feed.items": "{count}টি পণ্য",
    "analyticsLive.feed.item": "1টি পণ্য",

    "analyticsLive.viewing.title": "এখন যা দেখা হচ্ছে",
    "analyticsLive.viewing.subtitle": "পণ্য · গত {minutes} মিনিট",
    "analyticsLive.viewing.shoppers": "{count} জন ক্রেতা",
    "analyticsLive.viewing.shopper": "1 জন ক্রেতা",
    "analyticsLive.viewing.empty":
        "ট্র্যাকিংয়ে সম্মতি দেওয়া কেউ এখন কোনো পণ্য দেখছেন না।",
    "analyticsLive.viewing.noPages":
        "পেজ গোনা হয় না — দোকান শুধু পণ্য দেখার হিসাব রাখে।",

    "analyticsLive.hourly.title": "আজ ঘণ্টা অনুযায়ী অর্ডার",
    "analyticsLive.hourly.subtitle": "ঢাকার সময়",
    "analyticsLive.hourly.bar": "{hour}: {orders}টি অর্ডার",

    "analyticsLive.note.consent":
        "ক্রেতার সংখ্যা, কাজ আর পণ্য দেখা — এগুলো শুধু ট্র্যাকিংয়ে সম্মতি দেওয়া ক্রেতাদের। অর্ডারে সব অর্ডার আছে।",
    "analyticsLive.note.noGa4":
        "ক্রেতাদের Google Analytics সেশন দিয়ে গোনা হয়, তাই সেটিংস › ট্র্যাকিং-এ Google Analytics 4 চালু না করা পর্যন্ত সংখ্যা 0 থাকবে।",
    "analyticsLive.note.off":
        "ট্র্যাকিং বন্ধ, তাই এখানে শুধু অর্ডার দেখা যাচ্ছে। ক্রেতাদের দেখতে সেটিংস › ট্র্যাকিং-এ Google Analytics 4 বা Meta চালু করুন।",
};
