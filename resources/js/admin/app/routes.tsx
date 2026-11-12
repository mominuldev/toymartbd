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
import { OrdersPage } from '@/features/orders/OrdersPage';
import { OrderDetailPage } from '@/features/orders/OrderDetailPage';
import { OrderCreatePage } from '@/features/orders/OrderCreatePage';
import { OrderEditPage } from '@/features/orders/OrderEditPage';
import { MediaLibraryPage } from '@/features/media/MediaLibraryPage';
import { AutomationsPage } from '@/features/notifications/AutomationsPage';
import { NotificationLogPage } from '@/features/notifications/NotificationLogPage';
import { ReviewsPage } from '@/features/reviews/ReviewsPage';

// Sections with real screens; every other sidebar section renders ComingSoon.
const built = new Set(['/products', '/inventory', '/orders', '/store', '/automations']);

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
                    // Order board (Figma 205:681) and order detail (206:1475).
                    { path: 'orders', element: <OrdersPage /> },
                    // Create order (207:2243) and edit after placement (P4-ORD-06/07).
                    { path: 'orders/new', element: <OrderCreatePage /> },
                    { path: 'orders/:orderId', element: <OrderDetailPage /> },
                    { path: 'orders/:orderId/edit', element: <OrderEditPage /> },
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
