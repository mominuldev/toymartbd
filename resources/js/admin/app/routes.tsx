import { Navigate, type RouteObject } from 'react-router';
import { Shell } from './Shell';
import { Dashboard } from './Dashboard';
import { NotFound } from './NotFound';
import { ComingSoon } from './ComingSoon';
import { RouteError } from './RouteError';
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

// Sections with real screens; every other sidebar section renders ComingSoon.
const built = new Set(['/products', '/inventory', '/purchasing', '/orders', '/returns', '/store', '/automations']);

export const routes: RouteObject[] = [
    { path: '/login', element: <LoginPage />, errorElement: <RouteError /> },
    {
        element: <RequireAuth />,
        errorElement: <RouteError />,
        children: [
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
                    // Finance opens on the P&L, its first sub-page (06-admin-feature-checklist §1).
                    { path: 'finance', element: <Navigate to="/finance/pnl" replace /> },
                    { path: 'finance/pnl', element: <ProfitLossPage /> },
                    { path: 'finance/reports', element: <DailyReportsPage /> },
                    { path: 'finance/profitability', element: <ProfitabilityPage /> },
                    // P5 finance: expenses (Figma Expenses 222:13676), categories, the ledger browser + COD receivables.
                    { path: 'finance/expenses', element: <ExpensesPage /> },
                    { path: 'finance/expenses/categories', element: <ExpenseCategoriesPage /> },
                    { path: 'finance/ledger', element: <LedgerPage /> },
                    // Order board (Figma 205:681) and order detail (206:1475).
                    { path: 'orders', element: <OrdersPage /> },
                    // Create order (207:2243) and edit after placement (P4-ORD-06/07).
                    { path: 'orders/new', element: <OrderCreatePage /> },
                    { path: 'orders/:orderId', element: <OrderDetailPage /> },
                    { path: 'orders/:orderId/edit', element: <OrderEditPage /> },
                    // Returns, refunds & exchanges (Figma B · 10 241:35772).
                    { path: 'returns', element: <ReturnsPage /> },
                    // Online store: the media library (Figma 303:53185) is its first screen; the
                    // section's other pages are still to come.
                    { path: 'store', element: <Navigate to="/store/media" replace /> },
                    { path: 'store/media', element: <MediaLibraryPage /> },
                    { path: 'store/*', element: <ComingSoon title="nav.store" /> },
                    // Settings → Automations (Figma 223:15784): notification templates; the message log is not drawn.
                    { path: 'automations', element: <AutomationsPage /> },
                    { path: 'automations/log', element: <NotificationLogPage /> },
                    ...sectionPaths
                        .filter((item) => !built.has(item.path))
                        .map((item) => ({ path: `${item.path.slice(1)}/*`, element: <ComingSoon title={item.key} /> })),
                    { path: '*', element: <NotFound /> },
                ],
            },
        ],
    },
];
