import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';

/// The counter. Tap a product to add it, then take cash or card — the API
/// bills the basket and takes the stock down in one call.
class ShopScreen extends StatefulWidget {
  const ShopScreen({super.key});

  @override
  State<ShopScreen> createState() => _ShopScreenState();
}

class _ShopScreenState extends State<ShopScreen> {
  final List<BasketLine> _basket = [];

  List<Product> _products = const [];
  bool _loading = true;
  bool _selling = false;

  double get _total => _basket.fold(0, (sum, line) => sum + line.total);

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);

    try {
      final response =
          await SessionScope.of(context).api.get('/products', query: {'per_page': 100});

      setState(() {
        _products = ((response as Map)['data'] as List)
            .cast<Map>()
            .map((row) => Product.fromJson(row.cast<String, dynamic>()))
            .toList();
      });
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _add(Product product) {
    final existing = _basket.where((line) => line.product.id == product.id).firstOrNull;

    setState(() {
      if (existing == null) {
        _basket.add(BasketLine(product: product));
      } else if (existing.quantity < product.stock) {
        // Never let the counter sell more than the shelf holds.
        existing.quantity += 1;
      }
    });
  }

  void _remove(BasketLine line) {
    setState(() {
      if (line.quantity > 1) {
        line.quantity -= 1;
      } else {
        _basket.remove(line);
      }
    });
  }

  Future<void> _checkout(String method) async {
    if (_basket.isEmpty) return;

    setState(() => _selling = true);

    try {
      await SessionScope.of(context).api.post('/shop/sell', body: {
        'items': [
          for (final line in _basket)
            {'product_id': line.product.id, 'quantity': line.quantity},
        ],
        'pay_now': true,
        'method': method,
      });

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(TranslationsScope.of(context).t('shop.sold', 'Sale recorded.'))),
        );
        setState(_basket.clear);
      }

      await _load();
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) setState(() => _selling = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    return DetailScaffold(
      title: t.t('nav.shop', 'Shop'),
      child: Column(
        children: [
          Expanded(
            child: _loading && _products.isEmpty
                ? const LoadingState()
                : GridView.count(
                    padding: const EdgeInsets.all(16),
                    crossAxisCount: MediaQuery.sizeOf(context).width > 620 ? 3 : 2,
                    mainAxisSpacing: 12,
                    crossAxisSpacing: 12,
                    childAspectRatio: 1.25,
                    children: [
                      for (final product in _products)
                        _ProductTile(product: product, onTap: () => _add(product)),
                    ],
                  ),
          ),

          if (_basket.isNotEmpty)
            _BasketPanel(
              basket: _basket,
              total: _total,
              selling: _selling,
              onRemove: _remove,
              onCheckout: _checkout,
            ),
        ],
      ),
    );
  }
}

class _ProductTile extends StatelessWidget {
  const _ProductTile({required this.product, required this.onTap});

  final Product product;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Opacity(
      opacity: product.isSellable ? 1 : 0.4,
      child: GlassCard(
        onTap: product.isSellable ? onTap : null,
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    product.name,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w600,
                      fontSize: 13,
                    ),
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                  decoration: BoxDecoration(
                    color: (product.isLowStock ? AppTheme.danger : Colors.white)
                        .withValues(alpha: 0.15),
                    borderRadius: BorderRadius.circular(999),
                  ),
                  child: Text(
                    '${product.stock}',
                    style: TextStyle(
                      fontSize: 11,
                      color: product.isLowStock ? const Color(0xFFFDA4AF) : AppTheme.ink200,
                    ),
                  ),
                ),
              ],
            ),
            const Spacer(),
            MoneyText(product.price, size: 18),
            const SizedBox(height: 2),
            Text(product.category, style: Theme.of(context).textTheme.labelSmall),
          ],
        ),
      ),
    );
  }
}

/// The basket docks to the bottom so the grid stays reachable one-handed.
class _BasketPanel extends StatelessWidget {
  const _BasketPanel({
    required this.basket,
    required this.total,
    required this.selling,
    required this.onRemove,
    required this.onCheckout,
  });

  final List<BasketLine> basket;
  final double total;
  final bool selling;
  final void Function(BasketLine) onRemove;
  final void Function(String) onCheckout;

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    return GlassCard(
      strong: true,
      margin: const EdgeInsets.fromLTRB(12, 0, 12, 12),
      padding: const EdgeInsets.all(16),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          ConstrainedBox(
            constraints: const BoxConstraints(maxHeight: 160),
            child: ListView(
              shrinkWrap: true,
              children: [
                for (final line in basket)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    child: Row(
                      children: [
                        Text(
                          '${line.quantity}×',
                          style: const TextStyle(
                            color: AppTheme.brand,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            line.product.name,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(color: Colors.white, fontSize: 13),
                          ),
                        ),
                        MoneyText(line.total, size: 13),
                        IconButton(
                          onPressed: () => onRemove(line),
                          icon: const Icon(Icons.remove_circle_outline, size: 18),
                          color: AppTheme.ink400,
                          visualDensity: VisualDensity.compact,
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),

          const Divider(height: 20),
          Row(
            children: [
              Expanded(
                child: Text(
                  t.t('shop.total', 'Total'),
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ),
              MoneyText(total, size: 20),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: BrandButton(
                  label: t.t('shop.cash', 'Cash'),
                  icon: Icons.payments_outlined,
                  loading: selling,
                  onPressed: () => onCheckout('cash'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: selling ? null : () => onCheckout('card'),
                  icon: const Icon(Icons.credit_card, size: 18),
                  label: Text(t.t('shop.card', 'Card')),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

extension _FirstOrNull<E> on Iterable<E> {
  E? get firstOrNull => isEmpty ? null : first;
}
