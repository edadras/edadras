import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';
import 'package:intl/intl.dart';

import 'checkout_screen.dart';

/// What the member has paid, what they still owe, and their wallet credit.
class PaymentsScreen extends StatefulWidget {
  const PaymentsScreen({super.key});

  @override
  State<PaymentsScreen> createState() => _PaymentsScreenState();
}

class _PaymentsScreenState extends State<PaymentsScreen> {
  List<Invoice> _invoices = const [];
  List<WalletEntry> _wallet = const [];
  double _balance = 0;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final api = SessionScope.of(context).api;

    setState(() => _loading = true);

    try {
      final payments = await api.get('/me/payments');
      final wallet = await api.get('/me/wallet');

      setState(() {
        _invoices = (((payments as Map)['invoices'] as List?) ?? const [])
            .cast<Map>()
            .map((row) => Invoice.fromJson(row.cast<String, dynamic>()))
            .toList();
        _balance = double.tryParse('${(wallet as Map)['balance']}') ?? 0;
        _wallet = ((wallet['transactions'] as List?) ?? const [])
            .cast<Map>()
            .map((row) => WalletEntry.fromJson(row.cast<String, dynamic>()))
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

  /// Opens checkout, and refreshes when it comes back settled.
  Future<void> _checkout([Invoice? invoice]) async {
    final paid = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (_) => CheckoutScreen(invoice: invoice)),
    );

    if (paid == true && mounted) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    return DetailScaffold(
      title: t.t('members.payments', 'Payments'),
      child: _loading
          ? const LoadingState()
          : RefreshIndicator(
              onRefresh: _load,
              color: AppTheme.brand,
              backgroundColor: AppTheme.ink800,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  StatTile(
                    label: t.t('members.wallet', 'Wallet'),
                    value: _balance,
                    icon: '👛',
                    money: true,
                    hint: t.t('payments.top_up', 'Tap to top up'),
                    onTap: () => _checkout(),
                  ),

                  const SizedBox(height: 16),
                  GlassSection(
                    title: t.t('members.invoices', 'Invoices'),
                    child: _invoices.isEmpty
                        ? EmptyState(
                            icon: Icons.receipt_long_outlined,
                            message: t.t('members.no_invoices', 'No invoices yet.'),
                          )
                        : Column(
                            children: [
                              for (final invoice in _invoices)
                                _InvoiceRow(
                                  invoice: invoice,
                                  onPay: invoice.isPaid ? null : () => _checkout(invoice),
                                ),
                            ],
                          ),
                  ),

                  const SizedBox(height: 16),
                  GlassSection(
                    title: t.t('members.wallet_history', 'Wallet history'),
                    child: _wallet.isEmpty
                        ? EmptyState(
                            icon: Icons.account_balance_wallet_outlined,
                            message: t.t('members.no_wallet', 'No wallet movements yet.'),
                          )
                        : Column(
                            children: [
                              for (final entry in _wallet)
                                Padding(
                                  padding: const EdgeInsets.symmetric(vertical: 6),
                                  child: Row(
                                    children: [
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment: CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              entry.description ?? entry.type,
                                              maxLines: 1,
                                              overflow: TextOverflow.ellipsis,
                                              style: const TextStyle(
                                                color: Colors.white,
                                                fontSize: 13,
                                              ),
                                            ),
                                            Text(
                                              DateFormat.yMMMd().format(entry.createdAt),
                                              style:
                                                  Theme.of(context).textTheme.labelSmall,
                                            ),
                                          ],
                                        ),
                                      ),
                                      MoneyText(
                                        entry.amount,
                                        signed: true,
                                        positive: entry.isCredit,
                                      ),
                                    ],
                                  ),
                                ),
                            ],
                          ),
                  ),
                ],
              ),
            ),
    );
  }
}

class _InvoiceRow extends StatelessWidget {
  const _InvoiceRow({required this.invoice, this.onPay});

  final Invoice invoice;
  final VoidCallback? onPay;

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 7),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  invoice.items.isEmpty ? invoice.number : invoice.items.first,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(color: Colors.white, fontSize: 13),
                ),
                Text(
                  DateFormat.yMMMd().format(invoice.issuedAt),
                  style: Theme.of(context).textTheme.labelSmall,
                ),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              MoneyText(invoice.total, size: 13),
              if (!invoice.isPaid)
                Text(
                  '${t.t('members.due', 'Due')} ${formatAmount(invoice.balance)}',
                  style: const TextStyle(fontSize: 11, color: Color(0xFFFDA4AF)),
                ),
            ],
          ),
          if (onPay != null) ...[
            const SizedBox(width: 8),
            TextButton(
              onPressed: onPay,
              style: TextButton.styleFrom(
                foregroundColor: AppTheme.brand,
                padding: const EdgeInsets.symmetric(horizontal: 10),
                minimumSize: Size.zero,
                tapTargetSize: MaterialTapTargetSize.shrinkWrap,
              ),
              child: Text(t.t('payments.pay', 'Pay')),
            ),
          ],
        ],
      ),
    );
  }
}
