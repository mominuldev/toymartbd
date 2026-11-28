import { Navigate, useLocation, type RouteObject } from 'react-router';
import { Shell } from './Shell';
import { Dashboard } from './Dashboard';
import { NotFound } from './NotFound';
import { ComingSoon } from './ComingSoon';
import { RouteError } from './RouteError';
import { BlogPostEditorPage, BlogPostsPage, BlogTermsPage } from '@/features/content/blog';
import { RequireAuth } from '@/features/auth/RequireAuth';
import { TwoFactorPage } from '@/features/auth/TwoFactorPage';
import { AccountPage } from '@/features/auth/AccountPage';
import { LoginPage } from '@/features/auth/LoginPage';
import { sectionPaths } from '@/components/shell/nav';
import { CatalogLayout } from '@/features/catalog/CatalogLayout';
import { BrandsPage } from '@/features/catalog/brands/BrandsPage';
import { BrandFormPage } from '@/features/catalog/brands/BrandFormPage';
import { CategoriesPage } from '@/features/catalog/categories/CategoriesPage';
import { AttributesPage } from '@/features/catalog/attributes/AttributesPage';
import { AttributeFormPage } from '@/features/catalog/attributes/AttributeFormPage';
import { ProductsPage } from '@/features/catalog/products/ProductsPage';
import { ProductFormPage } from '@/features/catalog/products/ProductFormPage';
import { ProductLabelsPage } from '@/features/catalog/products/ProductLabelsPage';
import { InventoryPage } from '@/features/inventory/InventoryPage';
import { MovementsPage } from '@/features/inventory/MovementsPage';
import { AdjustmentsPage } from '@/features/inventory/AdjustmentsPage';
import { AlertsPage } from '@/features/inventory/AlertsPage';
import { OrdersPage } from '@/features/orders/OrdersPage';
import { OrderDetailPage } from '@/features/orders/OrderDetailPage';
import { OrderCreatePage } from '@/features/orders/OrderCreatePage';
import { OrderEditPage } from '@/features/orders/OrderEditPage';
import { MediaLibraryPage } from '@/features/media/MediaLibraryPage';
import { AutomationsPage } from '@/features/notifications/AutomationsPage';
import { NotificationLogPage } from '@/features/notifications/NotificationLogPage';
import { MessageTemplatesPage } from '@/features/notifications/MessageTemplatesPage';
import { NewTemplatePage } from '@/features/notifications/NewTemplatePage';
import { TemplateEditorPage } from '@/features/notifications/TemplateEditorPage';
import { MessagingSettingsPage } from '@/features/settings/messaging/MessagingSettingsPage';
import { MessengerChannelPage } from '@/features/settings/messaging/channels/MessengerChannelPage';
import { WhatsAppChannelPage } from '@/features/settings/messaging/channels/WhatsAppChannelPage';
import { InvoicesPage } from '@/features/invoices/InvoicesPage';
import { InvoicePrintTab } from '@/features/print/InvoicePrintTab';
import { ReceiptPrintTab } from '@/features/print/ReceiptPrintTab';
import { LabelsPrintTab } from '@/features/print/LabelsPrintTab';
import { TrackingDiagnosticsPage, TrackingSettingsPage } from '@/features/settings/tracking';
import { PeopleTab, RolesTab, StaffPage } from '@/features/staff';
import { ActivityLogPage } from '@/features/audit/ActivityLogPage';
import { IntegrationsHubPage } from '@/features/integrations/IntegrationsHubPage';
import { PaymentGatewaysPage } from '@/features/settings/payments/PaymentGatewaysPage';
import { SettingsLayout } from '@/features/storeSettings/SettingsLayout';
import { BusinessSection } from '@/features/storeSettings/BusinessSection';
import { SiteBrandingSection } from '@/features/storeSettings/SiteBrandingSection';
import { BranchesSection } from '@/features/storeSettings/BranchesSection';
import { CheckoutSection } from '@/features/storeSettings/CheckoutSection';
import { PosSection } from '@/features/storeSettings/PosSection';
import { DeliveryAreasSection } from '@/features/storeSettings/DeliveryAreasSection';
import { PosPage } from '@/features/pos/PosPage';
import { InboxPage } from '@/features/inbox/InboxPage';
import { ShipmentsPage } from '@/features/shipments/ShipmentsPage';
import { BookingQueuePage } from '@/features/shipments/BookingQueuePage';
import { ReviewsPage } from '@/features/reviews/ReviewsPage';
import { PurchaseOrdersPage } from '@/features/purchasing/PurchaseOrdersPage';
import { PurchaseFormPage } from '@/features/purchasing/PurchaseFormPage';
import { PurchaseDetailPage } from '@/features/purchasing/PurchaseDetailPage';
import { SuppliersPage } from '@/features/purchasing/SuppliersPage';
import { SupplierFormPage } from '@/features/purchasing/SupplierFormPage';
import { SupplierDetailPage } from '@/features/purchasing/SupplierDetailPage';
import { ReturnsPage } from '@/features/returns/ReturnsPage';
import { ReturnDetailPage } from '@/features/returns/ReturnDetailPage';
import { ProfitLossPage } from '@/features/finance/reports/ProfitLossPage';
import { DailyReportsPage } from '@/features/finance/reports/DailyReportsPage';
import { ProfitabilityPage } from '@/features/finance/reports/ProfitabilityPage';
import { ExpensesPage } from '@/features/finance/ExpensesPage';
import { ExpenseCategoriesPage } from '@/features/finance/ExpenseCategoriesPage';
import { LedgerPage } from '@/features/finance/LedgerPage';
import { InventoryValuationPage } from '@/features/reports/InventoryValuationPage';
import { CourierPerformancePage } from '@/features/reports/couriers/CourierPerformancePage';
import { SalesAnalyticsPage } from '@/features/analytics/sales/SalesAnalyticsPage';
import { AiSettingsPage } from '@/features/ai/settings/AiSettingsPage';
import { GrowthAdvisorPage } from '@/features/ai/advisor/GrowthAdvisorPage';
import { RestockForecastPage } from '@/features/ai/restock/RestockForecastPage';
import { ReportSchedulesPage } from '@/features/reports/schedules/ReportSchedulesPage';
import { CustomerProfilePage, CustomersPage } from '@/features/customers';
import { CustomerAnalyticsPage } from '@/features/analytics/customers';
import { ProductAnalyticsPage } from '@/features/analytics/products/ProductAnalyticsPage';
import { VisitorAnalyticsPage } from '@/features/analytics/visitors/VisitorAnalyticsPage';
import { MarketingAnalyticsPage } from '@/features/analytics/marketing/MarketingAnalyticsPage';
import { LiveAnalyticsPage } from '@/features/analytics/live/LiveAnalyticsPage';
import { LocationMappingPage } from '@/features/couriers/locations/LocationMappingPage';
import { CourierSettingsPage, FailedBookingsPage } from '@/features/couriers';
import { PhoneLookupPage, RiskRulesPage, VerificationQueuePage } from '@/features/risk';
import { SettlementsPage } from '@/features/finance/settlements/SettlementsPage';
import { SettlementDetailPage } from '@/features/finance/settlements/SettlementDetailPage';
import { CapitalPage } from '@/features/finance/capital/CapitalPage';
import { AbandonedCartsPage, CouponFormPage, CouponsPage, PromotionFormPage, PromotionsPage } from '@/features/marketing';
import { PagesPage } from '@/features/content/pages/PagesPage';
import { PageFormPage } from '@/features/content/pages/PageFormPage';
import { PageBuilderPage } from '@/features/content/pages/PageBuilderPage';
import { MenuEditorPage } from '@/features/content/menus/MenuEditorPage';
import { BannersPage } from '@/features/content/banners/BannersPage';
import { BannerFormPage } from '@/features/content/banners/BannerFormPage';
import { TestimonialsPage } from '@/features/content/testimonials/TestimonialsPage';
import { TestimonialFormPage } from '@/features/content/testimonials/TestimonialFormPage';
import { HomeBuilderPage } from '@/features/content/home/HomeBuilderPage';
import { HomeSettingsPage } from '@/features/content/home/HomeSettingsPage';
import { RedirectsPage, SeoOverviewPage, SitemapRobotsPage } from '@/features/seo';

/** A moved page: redirect, keeping the query string and hash (a Facebook login returns with `?code&state`). */
function KeepQueryRedirect({ to }: { to: string }) {
    const { search, hash } = useLocation();
    return <Navigate to={`${to}${search}${hash}`} replace />;
}

// Sections with real screens; every other sidebar section renders ComingSoon.
const built = new Set(['/products', '/inventory', '/purchasing', '/orders', '/returns', '/store', '/automations', '/risk', '/seo', '/marketing', '/customers', '/invoices', '/settings', '/staff', '/pos', '/inbox', '/shipments']);

export const routes: RouteObject[] = [
    { path: '/login', element: <LoginPage />, errorElement: <RouteError /> },
    {
        element: <RequireAuth />,
        errorElement: <RouteError />,
        children: [
            // Homepage builder (Figma B · 26 Page builder 241:44102): full height beside the sidebar
            // rail, no topbar, so it sits outside the Shell. Static, so it wins over `store/*`.
            { path: '/store/home', element: <HomeBuilderPage /> },
            // A static page's sections, in the same builder (owner decision 2026-11-16) — beside the
            // store/pages routes in spirit, but full height outside the Shell like the homepage.
            { path: '/store/pages/:pageId/build', element: <PageBuilderPage /> },
            // POS register (Figma B · 33 241:48158): sidebar rail and its own header, no topbar — outside the Shell.
            { path: '/pos', element: <PosPage /> },
            // Two-factor sign-in (owner decision 2026-11-14; no frame — the sign-in frame B · 00).
            // Outside the Shell: someone who must enrol is held here before using the admin.
            { path: '/account/two-factor', element: <TwoFactorPage /> },
            {
                path: '/',
                element: <Shell />,
                children: [
                    { index: true, element: <Dashboard /> },
                    // My account (B · 40 547:83811): your own name, email and password.
                    { path: 'account', element: <AccountPage /> },
                    {
                        path: 'products',
                        element: <CatalogLayout />,
                        children: [
                            { index: true, element: <ProductsPage /> },
                            { path: 'categories', element: <CategoriesPage /> },
                            // One split view (Figma Categories 212:7132): the URL carries the selection.
                            { path: 'categories/new', element: <CategoriesPage /> },
                            { path: 'categories/:categoryId', element: <CategoriesPage /> },
                            { path: 'brands', element: <BrandsPage /> },
                            { path: 'brands/new', element: <BrandFormPage /> },
                            { path: 'brands/:brandId', element: <BrandFormPage /> },
                            { path: 'attributes', element: <AttributesPage /> },
                            { path: 'attributes/new', element: <AttributeFormPage /> },
                            { path: 'attributes/:attributeId', element: <AttributeFormPage /> },
                            { path: 'reviews', element: <ReviewsPage /> },
                        ],
                    },
                    // The product form has its own header and tabs (Figma 241:37883), so it sits
                    // outside the catalog tab strip. Static siblings (categories, brands …) still win.
                    { path: 'products/new', element: <ProductFormPage /> },
                    // Print labels (owner request 2026-11-24): its own page, `?ids=` from the list.
                    { path: 'products/labels', element: <ProductLabelsPage /> },
                    { path: 'products/:productId', element: <ProductFormPage /> },
                    // Inventory overview (Figma 213:6955) and the movement ledger (213:7516).
                    { path: 'inventory', element: <InventoryPage /> },
                    { path: 'inventory/movements', element: <MovementsPage /> },
                    { path: 'inventory/adjustments', element: <AdjustmentsPage /> },
                    { path: 'inventory/alerts', element: <AlertsPage /> },
                    // AI for growth (Figma 540:83487): restock forecast with an AI note.
                    { path: 'inventory/restock', element: <RestockForecastPage /> },
                    // Purchasing (Figma "16 Purchase orders" 213:7971, Theme B): POs with the receive card,
                    // PO form/detail, suppliers with payables. Static siblings win over :purchaseId.
                    { path: 'purchasing', element: <PurchaseOrdersPage /> },
                    { path: 'purchasing/new', element: <PurchaseFormPage /> },
                    { path: 'purchasing/suppliers', element: <SuppliersPage /> },
                    { path: 'purchasing/suppliers/new', element: <SupplierFormPage /> },
                    { path: 'purchasing/suppliers/:supplierId', element: <SupplierDetailPage /> },
                    { path: 'purchasing/suppliers/:supplierId/edit', element: <SupplierFormPage /> },
                    { path: 'purchasing/:purchaseId', element: <PurchaseDetailPage /> },
                    { path: 'purchasing/:purchaseId/edit', element: <PurchaseFormPage /> },
                    // P5 reports: inventory valuation, and the finance P&L (Figma B · 31 241:47268), daily reports, profitability.
                    { path: 'inventory/valuation', element: <InventoryValuationPage /> },
                    // P6 Analytics → Couriers (no frame; B · 24 Analytics 241:30584 language). Analytics' other tabs are not built yet.
                    { path: 'analytics', element: <Navigate to="/analytics/sales" replace /> },
                    // P8: sales analytics (Figma B · 24 241:30584) and scheduled report emails.
                    { path: 'analytics/sales', element: <SalesAnalyticsPage /> },
                    // AI for growth (Figma 537:82561): weekly brief, suggested actions, Ask AI.
                    { path: 'analytics/advisor', element: <GrowthAdvisorPage /> },
                    { path: 'analytics/schedules', element: <ReportSchedulesPage /> },
                    { path: 'analytics/couriers', element: <CourierPerformancePage /> },
                    // P8 Analytics → Customers (no frame; B · 24 Analytics 241:30584 language): LTV, cohorts, repeat rate, segments.
                    { path: 'analytics/customers', element: <CustomerAnalyticsPage /> },
                    { path: 'analytics/products', element: <ProductAnalyticsPage /> },
                    { path: 'analytics/visitors', element: <VisitorAnalyticsPage /> },
                    { path: 'analytics/marketing', element: <MarketingAnalyticsPage /> },
                    { path: 'analytics/live', element: <LiveAnalyticsPage /> },
                    // P8 Customers list (Figma B · 17 241:40845) and profile (B · 18 241:41374).
                    { path: 'customers', element: <CustomersPage /> },
                    { path: 'customers/:customerId', element: <CustomerProfilePage /> },
                    // Finance opens on the P&L, its first sub-page (06-admin-feature-checklist §1).
                    { path: 'finance', element: <Navigate to="/finance/pnl" replace /> },
                    { path: 'finance/pnl', element: <ProfitLossPage /> },
                    { path: 'finance/reports', element: <DailyReportsPage /> },
                    { path: 'finance/profitability', element: <ProfitabilityPage /> },
                    // P5 finance: expenses (Figma Expenses 222:13676), categories, the ledger browser + COD receivables.
                    { path: 'finance/expenses', element: <ExpensesPage /> },
                    { path: 'finance/expenses/categories', element: <ExpenseCategoriesPage /> },
                    { path: 'finance/ledger', element: <LedgerPage /> },
                    // P6 COD settlements (Figma B · 09 241:35325): courier remittances matched line by line.
                    { path: 'finance/settlements', element: <SettlementsPage /> },
                    // P10 finance premium pass: one statement line by line (Figma B · 32 COD settlement detail 426:73739).
                    { path: 'finance/settlements/:id', element: <SettlementDetailPage /> },
                    // P10 owner capital (Figma B · 32 Finance › Capital 428:74277).
                    { path: 'finance/capital', element: <CapitalPage /> },
                    // Order board (Figma 205:681) and order detail (206:1475).
                    { path: 'orders', element: <OrdersPage /> },
                    // Create order (207:2243) and edit after placement (P4-ORD-06/07).
                    { path: 'orders/new', element: <OrderCreatePage /> },
                    { path: 'orders/:orderId', element: <OrderDetailPage /> },
                    { path: 'orders/:orderId/edit', element: <OrderEditPage /> },
                    // Returns & refunds, one column: the list (Figma B · 10 Returns · Premium · List 582:50341)
                    // and each return on its own page (Return detail 583:50649 / 584:50961).
                    { path: 'returns', element: <ReturnsPage /> },
                    { path: 'returns/:returnId', element: <ReturnDetailPage /> },
                    // P6 Fraud & risk: verification queue (B · 05 241:36206), phone lookup (B · 06 241:36699), risk rules (settings form pattern).
                    { path: 'risk', element: <Navigate to="/risk/queue" replace /> },
                    { path: 'risk/queue', element: <VerificationQueuePage /> },
                    { path: 'risk/lookup', element: <PhoneLookupPage /> },
                    { path: 'risk/rules', element: <RiskRulesPage /> },
                    // P7 Marketing: coupons + builder (Figma B · 20 241:42268), promotions and flash sales
                    // (B · 20 builder layout, no frame of their own), abandoned carts (B · 21 241:42726).
                    { path: 'marketing', element: <Navigate to="/marketing/coupons" replace /> },
                    { path: 'marketing/coupons', element: <CouponsPage /> },
                    { path: 'marketing/coupons/new', element: <CouponFormPage /> },
                    { path: 'marketing/coupons/generate', element: <CouponFormPage mode="generate" /> },
                    { path: 'marketing/coupons/:couponId', element: <CouponFormPage key="edit" /> },
                    { path: 'marketing/promotions', element: <PromotionsPage /> },
                    { path: 'marketing/promotions/new', element: <PromotionFormPage /> },
                    { path: 'marketing/promotions/:promotionId', element: <PromotionFormPage key="edit" /> },
                    { path: 'marketing/flash-sales', element: <PromotionsPage flash key="flash" /> },
                    { path: 'marketing/flash-sales/new', element: <PromotionFormPage flash key="flash-new" /> },
                    { path: 'marketing/flash-sales/:promotionId', element: <PromotionFormPage flash key="flash-edit" /> },
                    { path: 'marketing/abandoned-carts', element: <AbandonedCartsPage /> },
                    // Online store opens on Pages; the media library (Figma 303:53185) is its last submenu item.
                    { path: 'store', element: <Navigate to="/store/pages" replace /> },
                    { path: 'store/media', element: <MediaLibraryPage /> },
                    // P7 Pages & navigation (Figma B · 27 241:45525): pages, header/footer/mobile menus;
                    // banners and testimonials have no frame and share the same tab row.
                    { path: 'store/pages', element: <PagesPage /> },
                    // Home → Edit: the homepage's SEO (owner request 2026-11-18); its builder is /store/home.
                    { path: 'store/home/settings', element: <HomeSettingsPage /> },
                    { path: 'store/pages/new', element: <PageFormPage /> },
                    { path: 'store/pages/:pageId', element: <PageFormPage /> },
                    { path: 'store/navigation', element: <Navigate to="/store/navigation/header" replace /> },
                    { path: 'store/navigation/:location', element: <MenuEditorPage /> },
                    { path: 'store/banners', element: <BannersPage /> },
                    { path: 'store/banners/new', element: <BannerFormPage /> },
                    { path: 'store/banners/:bannerId', element: <BannerFormPage /> },
                    { path: 'store/testimonials', element: <TestimonialsPage /> },
                    { path: 'store/testimonials/new', element: <TestimonialFormPage /> },
                    { path: 'store/testimonials/:testimonialId', element: <TestimonialFormPage /> },
                    // Blog (Phase 7): posts + buying guides (editor = Figma B · 28 241:46011), categories, tags, authors.
                    { path: 'store/blog', element: <BlogPostsPage /> },
                    { path: 'store/blog/new', element: <BlogPostEditorPage /> },
                    { path: 'store/blog/categories', element: <BlogTermsPage key="categories" kind="categories" /> },
                    { path: 'store/blog/tags', element: <BlogTermsPage key="tags" kind="tags" /> },
                    { path: 'store/blog/authors', element: <BlogTermsPage key="authors" kind="authors" /> },
                    { path: 'store/blog/:postId', element: <BlogPostEditorPage /> },
                    { path: 'store/*', element: <ComingSoon title="nav.store" /> },
                    // Settings → Automations (Figma 223:15784): notification templates; the message log is not drawn.
                    { path: 'automations', element: <AutomationsPage /> },
                    { path: 'automations/log', element: <NotificationLogPage /> },
                    // P7 Message templates library (button on B · 36 241:49914; no frame of its own).
                    { path: 'automations/templates', element: <MessageTemplatesPage /> },
                    { path: 'automations/templates/new', element: <NewTemplatePage /> },
                    { path: 'automations/templates/:templateId', element: <TemplateEditorPage /> },
                    // Unified inbox (Figma B · 22 241:43115); `:conversationId` selects a thread.
                    { path: 'inbox', element: <InboxPage /> },
                    // The channel pages moved to Integrations (2026-11-28). Old links keep their query string, so a
                    // Facebook login that went out with the old redirect URI still finishes.
                    { path: 'inbox/channels/messenger', element: <KeepQueryRedirect to="/integrations/messenger" /> },
                    { path: 'inbox/channels/whatsapp', element: <KeepQueryRedirect to="/integrations/whatsapp" /> },
                    { path: 'inbox/:conversationId', element: <InboxPage /> },
                    // Integrations hub (Figma B · 37 241:50294): every outside service with its health.
                    { path: 'integrations', element: <IntegrationsHubPage /> },
                    // One tab per channel: SMS · WhatsApp (order updates + Cloud API keys) · Facebook Messenger (keys).
                    // Same element, no key: the form stays mounted (and unsaved edits survive) across tabs.
                    { path: 'integrations/messaging', element: <MessagingSettingsPage /> },
                    { path: 'integrations/messaging/:tab', element: <MessagingSettingsPage /> },
                    // Social channels (Figma 623:50926): connect the Facebook Page (Facebook login) and WhatsApp (guided setup).
                    { path: 'integrations/messenger', element: <MessengerChannelPage /> },
                    { path: 'integrations/whatsapp', element: <WhatsAppChannelPage /> },
                    { path: 'integrations/payments', element: <PaymentGatewaysPage /> },
                    // P8 invoicing: the list (no frame; Purchase orders B · 16 DataTable pattern) and
                    // Settings → Invoice details (Store settings B · 38 241:50827 "Business" card).
                    { path: 'invoices', element: <InvoicesPage /> },
                    // Settings → Staff & roles → Activity log (Figma B · 39 241:51276). Static, so it wins over `staff`'s children.
                    { path: 'staff/activity', element: <ActivityLogPage /> },
                    // Settings → Staff & roles (Figma B · 35 241:48916): People and the role matrix.
                    {
                        path: 'staff',
                        element: <StaffPage />,
                        children: [
                            { index: true, element: <PeopleTab /> },
                            { path: 'roles', element: <RolesTab /> },
                        ],
                    },
                    // Settings › Store (Figma B · 38 241:50827): one layout, a route per section.
                    {
                        path: 'settings',
                        element: <SettingsLayout />,
                        children: [
                            { index: true, element: <Navigate to="/settings/business" replace /> },
                            { path: 'business', element: <BusinessSection /> },
                            { path: 'branding', element: <SiteBrandingSection /> },
                            { path: 'branches', element: <BranchesSection /> },
                            { path: 'checkout', element: <CheckoutSection /> },
                            { path: 'delivery', element: <DeliveryAreasSection /> },
                            { path: 'pos', element: <PosSection /> },
                            // Invoices, receipts & labels (owner request 2026-11-17, Figma 528:81351): one tab per document.
                            { path: 'invoice', element: <InvoicePrintTab /> },
                            { path: 'invoice/receipt', element: <ReceiptPrintTab /> },
                            { path: 'invoice/labels', element: <LabelsPrintTab /> },
                        ],
                    },
                    // P7 server-side tracking: Tracking & pixels (Figma B · 25 241:31086) and its Diagnostics (no frame).
                    { path: 'integrations/tracking', element: <TrackingSettingsPage /> },
                    { path: 'integrations/tracking/diagnostics', element: <TrackingDiagnosticsPage /> },
                    // P6 couriers: settings & delivery charges (no frame; B · 37 / B · 07 parts) and the failed-booking retry queue.
                    { path: 'integrations/couriers', element: <CourierSettingsPage /> },
                    // AI for growth (Figma 536:82215): providers, routing, cap, sharing.
                    { path: 'integrations/ai', element: <AiSettingsPage /> },
                    // Shipments & tracking (Figma B · 08 241:34682) and the courier booking queue (B · 07 241:34046).
                    { path: 'shipments', element: <ShipmentsPage /> },
                    { path: 'shipments/booking', element: <BookingQueuePage /> },
                    { path: 'shipments/failed', element: <FailedBookingsPage /> },
                    // Courier location mapping (P6, no Figma frame; linked from Shipments B · 08 "map it").
                    { path: 'shipments/locations', element: <LocationMappingPage /> },
                    // P7 SEO: overview (Figma B · 29 241:46427), Redirects (B · 30 241:46849), sitemap & robots.txt (no own frame).
                    { path: 'seo', element: <SeoOverviewPage /> },
                    { path: 'seo/redirects', element: <RedirectsPage /> },
                    { path: 'seo/sitemap', element: <SitemapRobotsPage /> },
                    ...sectionPaths
                        .filter((item) => !built.has(item.path))
                        .map((item) => ({ path: `${item.path.slice(1)}/*`, element: <ComingSoon title={item.key} /> })),
                    { path: '*', element: <NotFound /> },
                ],
            },
        ],
    },
];
