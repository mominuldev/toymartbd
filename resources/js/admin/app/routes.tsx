import { Navigate, type RouteObject } from 'react-router';
import { Shell } from './Shell';
import { Dashboard } from './Dashboard';
import { NotFound } from './NotFound';
import { ComingSoon } from './ComingSoon';
import { RouteError } from './RouteError';
import { BlogPostEditorPage, BlogPostsPage, BlogTermsPage } from '@/features/content/blog';
import { RequireAuth } from '@/features/auth/RequireAuth';
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
import { InvoicesPage } from '@/features/invoices/InvoicesPage';
import { InvoiceSettingsPage } from '@/features/invoices/InvoiceSettingsPage';
import { TrackingDiagnosticsPage, TrackingSettingsPage } from '@/features/settings/tracking';
import { ReviewsPage } from '@/features/reviews/ReviewsPage';
import { PurchaseOrdersPage } from '@/features/purchasing/PurchaseOrdersPage';
import { PurchaseFormPage } from '@/features/purchasing/PurchaseFormPage';
import { PurchaseDetailPage } from '@/features/purchasing/PurchaseDetailPage';
import { SuppliersPage } from '@/features/purchasing/SuppliersPage';
import { SupplierFormPage } from '@/features/purchasing/SupplierFormPage';
import { SupplierDetailPage } from '@/features/purchasing/SupplierDetailPage';
import { ReturnsPage } from '@/features/returns/ReturnsPage';
import { ProfitLossPage } from '@/features/finance/reports/ProfitLossPage';
import { DailyReportsPage } from '@/features/finance/reports/DailyReportsPage';
import { ProfitabilityPage } from '@/features/finance/reports/ProfitabilityPage';
import { ExpensesPage } from '@/features/finance/ExpensesPage';
import { ExpenseCategoriesPage } from '@/features/finance/ExpenseCategoriesPage';
import { LedgerPage } from '@/features/finance/LedgerPage';
import { InventoryValuationPage } from '@/features/reports/InventoryValuationPage';
import { CourierPerformancePage } from '@/features/reports/couriers/CourierPerformancePage';
import { SalesAnalyticsPage } from '@/features/analytics/sales/SalesAnalyticsPage';
import { ReportSchedulesPage } from '@/features/reports/schedules/ReportSchedulesPage';
import { CustomerProfilePage, CustomersPage } from '@/features/customers';
import { CustomerAnalyticsPage } from '@/features/analytics/customers';
import { LocationMappingPage } from '@/features/couriers/locations/LocationMappingPage';
import { CourierSettingsPage, FailedBookingsPage } from '@/features/couriers';
import { PhoneLookupPage, RiskRulesPage, VerificationQueuePage } from '@/features/risk';
import { SettlementsPage } from '@/features/finance/settlements/SettlementsPage';
import { AbandonedCartsPage, CouponFormPage, CouponsPage, PromotionFormPage, PromotionsPage } from '@/features/marketing';
import { PagesPage } from '@/features/content/pages/PagesPage';
import { PageFormPage } from '@/features/content/pages/PageFormPage';
import { MenuEditorPage } from '@/features/content/menus/MenuEditorPage';
import { BannersPage } from '@/features/content/banners/BannersPage';
import { BannerFormPage } from '@/features/content/banners/BannerFormPage';
import { TestimonialsPage } from '@/features/content/testimonials/TestimonialsPage';
import { TestimonialFormPage } from '@/features/content/testimonials/TestimonialFormPage';
import { HomeBuilderPage } from '@/features/content/home/HomeBuilderPage';
import { RedirectsPage, SeoOverviewPage, SitemapRobotsPage } from '@/features/seo';

// Sections with real screens; every other sidebar section renders ComingSoon.
const built = new Set(['/products', '/inventory', '/purchasing', '/orders', '/returns', '/store', '/automations', '/risk', '/seo', '/marketing', '/customers', '/invoices', '/settings']);

export const routes: RouteObject[] = [
    { path: '/login', element: <LoginPage />, errorElement: <RouteError /> },
    {
        element: <RequireAuth />,
        errorElement: <RouteError />,
        children: [
            // Homepage builder (Figma B · 26 Page builder 241:44102): full height beside the sidebar
            // rail, no topbar, so it sits outside the Shell. Static, so it wins over `store/*`.
            { path: '/store/home', element: <HomeBuilderPage /> },
            {
                path: '/',
                element: <Shell />,
                children: [
                    { index: true, element: <Dashboard /> },
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
                    { path: 'products/:productId', element: <ProductFormPage /> },
                    // Inventory overview (Figma 213:6955) and the movement ledger (213:7516).
                    { path: 'inventory', element: <InventoryPage /> },
                    { path: 'inventory/movements', element: <MovementsPage /> },
                    { path: 'inventory/adjustments', element: <AdjustmentsPage /> },
                    { path: 'inventory/alerts', element: <AlertsPage /> },
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
                    { path: 'analytics/schedules', element: <ReportSchedulesPage /> },
                    { path: 'analytics/couriers', element: <CourierPerformancePage /> },
                    // P8 Analytics → Customers (no frame; B · 24 Analytics 241:30584 language): LTV, cohorts, repeat rate, segments.
                    { path: 'analytics/customers', element: <CustomerAnalyticsPage /> },
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
                    // Order board (Figma 205:681) and order detail (206:1475).
                    { path: 'orders', element: <OrdersPage /> },
                    // Create order (207:2243) and edit after placement (P4-ORD-06/07).
                    { path: 'orders/new', element: <OrderCreatePage /> },
                    { path: 'orders/:orderId', element: <OrderDetailPage /> },
                    { path: 'orders/:orderId/edit', element: <OrderEditPage /> },
                    // Returns, refunds & exchanges (Figma B · 10 241:35772).
                    { path: 'returns', element: <ReturnsPage /> },
                    // P6 Fraud & risk: verification queue (B · 05 241:36206), phone lookup (B · 06 241:36699), risk rules (settings form pattern).
                    { path: 'risk', element: <Navigate to="/risk/queue" replace /> },
                    { path: 'risk/queue', element: <VerificationQueuePage /> },
                    { path: 'risk/lookup', element: <PhoneLookupPage /> },
                    { path: 'risk/rules', element: <RiskRulesPage /> },
                    // Online store: the media library (Figma 303:53185) is its first screen; the
                    // section's other pages are still to come.
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
                    { path: 'store', element: <Navigate to="/store/media" replace /> },
                    { path: 'store/media', element: <MediaLibraryPage /> },
                    // P7 Pages & navigation (Figma B · 27 241:45525): pages, header/footer/mobile menus;
                    // banners and testimonials have no frame and share the same tab row.
                    { path: 'store/pages', element: <PagesPage /> },
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
                    // Integrations: the hub (Figma B · 37) is not built yet, so the section opens on Messaging.
                    { path: 'integrations', element: <Navigate to="/integrations/messaging" replace /> },
                    { path: 'integrations/messaging', element: <MessagingSettingsPage /> },
                    // P8 invoicing: the list (no frame; Purchase orders B · 16 DataTable pattern) and
                    // Settings → Invoice details (Store settings B · 38 241:50827 "Business" card).
                    { path: 'invoices', element: <InvoicesPage /> },
                    { path: 'settings', element: <Navigate to="/settings/invoice" replace /> },
                    { path: 'settings/invoice', element: <InvoiceSettingsPage /> },
                    // P7 server-side tracking: Tracking & pixels (Figma B · 25 241:31086) and its Diagnostics (no frame).
                    { path: 'integrations/tracking', element: <TrackingSettingsPage /> },
                    { path: 'integrations/tracking/diagnostics', element: <TrackingDiagnosticsPage /> },
                    // P6 couriers: settings & delivery charges (no frame; B · 37 / B · 07 parts) and the failed-booking retry queue.
                    { path: 'integrations/couriers', element: <CourierSettingsPage /> },
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
