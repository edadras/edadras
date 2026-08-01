import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';
import 'package:intl/intl.dart';

/// The daily till plus the recent movements, and the one form the desk
/// actually needs on a phone: recording an expense.
class FinanceScreen extends StatefulWidget {
  const FinanceScreen({super.key});

  @override
  State<FinanceScreen> createState() => _FinanceScreenState();
}

class _FinanceScreenState extends State<FinanceScreen> {
  DailyRegister? _register;
  List<CashTransaction> _transactions = const [];
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
      final register = await api.get('/finance/register');
      final transactions = await api.get('/finance/transactions', query: {'per_page': 40});

      setState(() {
        _register = DailyRegister.fromJson((register as Map).cast<String, dynamic>());
        _transactions = ((transactions as Map)['data'] as List)
            .cast<Map>()
            .map((row) => CashTransaction.fromJson(row.cast<String, dynamic>()))
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

  Future<void> _openExpenseSheet() async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => const _ExpenseSheet(),
    );

    if (saved == true) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);
    final session = SessionScope.of(context);

    return DetailScaffold(
      title: t.t('nav.finance', 'Finance'),
      floatingActionButton: session.can('finance.create')
          ? FloatingActionButton.extended(
              onPressed: _openExpenseSheet,
              backgroundColor: AppTheme.brand,
              foregroundColor: AppTheme.ink900,
              icon: const Icon(Icons.remove_circle_outline),
              label: Text(t.t('finance.add_expense', 'Expense')),
            )
          : null,
      child: _loading && _register == null
          ? const LoadingState()
          : RefreshIndicator(
              onRefresh: _load,
              color: AppTheme.brand,
              backgroundColor: AppTheme.ink800,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 90),
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: StatTile(
                          label: t.t('finance.income_today', 'Income today'),
                          value: _register?.income ?? 0,
                          icon: '📥',
                          money: true,
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: StatTile(
                          label: t.t('finance.expense_today', 'Expenses today'),
                          value: _register?.expense ?? 0,
                          icon: '📤',
                          money: true,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  StatTile(
                    label: t.t('finance.net_today', 'Net today'),
                    value: _register?.net ?? 0,
                    icon: '🧮',
                    money: true,
                  ),

                  if ((_register?.byMethod ?? const {}).isNotEmpty) ...[
                    const SizedBox(height: 16),
                    GlassSection(
                      title: t.t('finance.by_method', 'By payment method'),
                      child: Column(
                        children: [
                          for (final entry in _register!.byMethod.entries)
                            Padding(
                              padding: const EdgeInsets.symmetric(vertical: 5),
                              child: Row(
                                children: [
                                  Expanded(
                                    child: Text(
                                      entry.key,
                                      style: Theme.of(context).textTheme.bodySmall,
                                    ),
                                  ),
                                  MoneyText(entry.value),
                                ],
                              ),
                            ),
                        ],
                      ),
                    ),
                  ],

                  const SizedBox(height: 16),
                  GlassSection(
                    title: t.t('finance.transactions', 'Transactions'),
                    child: _transactions.isEmpty
                        ? EmptyState(
                            icon: Icons.receipt_long_outlined,
                            message: t.t('finance.empty', 'No transactions in this period.'),
                          )
                        : Column(
                            children: [
                              for (final transaction in _transactions)
                                _TransactionRow(transaction: transaction),
                            ],
                          ),
                  ),
                ],
              ),
            ),
    );
  }
}

class _TransactionRow extends StatelessWidget {
  const _TransactionRow({required this.transaction});

  final CashTransaction transaction;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 7),
      child: Row(
        children: [
          Icon(
            transaction.isIncome ? Icons.south_west : Icons.north_east,
            size: 16,
            color: transaction.isIncome ? AppTheme.brand : const Color(0xFFFDA4AF),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  transaction.description?.isNotEmpty == true
                      ? transaction.description!
                      : transaction.category,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(color: Colors.white, fontSize: 13),
                ),
                Text(
                  '${DateFormat.MMMd().add_Hm().format(transaction.occurredAt)} · ${transaction.method}',
                  style: Theme.of(context).textTheme.labelSmall,
                ),
              ],
            ),
          ),
          MoneyText(transaction.amount, signed: true, positive: transaction.isIncome),
        ],
      ),
    );
  }
}

/// The expense form, as a sheet so the till stays visible behind it.
class _ExpenseSheet extends StatefulWidget {
  const _ExpenseSheet();

  @override
  State<_ExpenseSheet> createState() => _ExpenseSheetState();
}

class _ExpenseSheetState extends State<_ExpenseSheet> {
  static const _categories = [
    'rent',
    'salary',
    'bills',
    'equipment',
    'marketing',
    'tax',
    'supplies',
    'other',
  ];

  final _amount = TextEditingController();
  final _description = TextEditingController();
  String _category = 'bills';
  String _method = 'cash';
  bool _saving = false;

  @override
  void dispose() {
    _amount.dispose();
    _description.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final amount = double.tryParse(_amount.text.trim());

    if (amount == null || amount <= 0) return;

    setState(() => _saving = true);

    try {
      await SessionScope.of(context).api.post('/finance/expenses', body: {
        'amount': amount,
        'category': _category,
        'method': _method,
        'description': _description.text.trim(),
      });

      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
        setState(() => _saving = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: GlassCard(
        strong: true,
        margin: const EdgeInsets.all(12),
        padding: const EdgeInsets.all(20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              t.t('finance.add_expense', 'Record an expense'),
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _amount,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              autofocus: true,
              decoration: InputDecoration(labelText: t.t('finance.amount', 'Amount')),
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              value: _category,
              dropdownColor: AppTheme.ink800,
              decoration: InputDecoration(labelText: t.t('finance.category', 'Category')),
              items: [
                for (final category in _categories)
                  DropdownMenuItem(value: category, child: Text(category)),
              ],
              onChanged: (value) => setState(() => _category = value ?? 'other'),
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              value: _method,
              dropdownColor: AppTheme.ink800,
              decoration: InputDecoration(labelText: t.t('finance.method', 'Method')),
              items: const [
                DropdownMenuItem(value: 'cash', child: Text('cash')),
                DropdownMenuItem(value: 'card', child: Text('card')),
                DropdownMenuItem(value: 'transfer', child: Text('transfer')),
              ],
              onChanged: (value) => setState(() => _method = value ?? 'cash'),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _description,
              decoration:
                  InputDecoration(labelText: t.t('finance.description', 'Description')),
            ),
            const SizedBox(height: 20),
            BrandButton(
              label: t.t('general.save', 'Save'),
              loading: _saving,
              onPressed: _save,
            ),
          ],
        ),
      ),
    );
  }
}
