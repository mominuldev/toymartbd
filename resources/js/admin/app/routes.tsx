import type { RouteObject } from 'react-router';
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

// Sections with real screens; every other sidebar section renders ComingSoon.
const built = new Set(['/products', '/inventory', '/orders']);

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
                    { path: 'orders/:orderId', element: <OrderDetailPage /> },
                    ...sectionPaths
                        .filter((item) => !built.has(item.path))
                        .map((item) => ({ path: `${item.path.slice(1)}/*`, element: <ComingSoon title={item.key} /> })),
                    { path: '*', element: <NotFound /> },
                ],
            },
        ],
    },
];
