import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';

/// The coaching staff, their contracts, and paying a salary from the phone.
class CoachesScreen extends StatefulWidget {
  const CoachesScreen({super.key});

  @override
  State<CoachesScreen> createState() => _CoachesScreenState();
}

class _CoachesScreenState extends State<CoachesScreen> {
  List<Map<String, dynamic>> _coaches = const [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);

    try {
      final response =
          await SessionScope.of(context).api.get('/coaches', query: {'per_page': 100});

      setState(() {
        _coaches = ((response as Map)['data'] as List)
            .cast<Map>()
            .map((row) => row.cast<String, dynamic>())
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

  Future<void> _paySalary(Map<String, dynamic> coach) async {
    final t = TranslationsScope.of(context);
    final controller = TextEditingController(text: '${coach['salary_amount'] ?? ''}');

    final amount = await showDialog<double>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        backgroundColor: AppTheme.ink800,
        title: Text(t.t('coaches.pay_salary', 'Pay salary')),
        content: TextField(
          controller: controller,
          autofocus: true,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: InputDecoration(labelText: t.t('finance.amount', 'Amount')),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(),
            child: Text(t.t('general.cancel', 'Cancel')),
          ),
          FilledButton(
            onPressed: () =>
                Navigator.of(dialogContext).pop(double.tryParse(controller.text.trim())),
            child: Text(t.t('general.save', 'Save')),
          ),
        ],
      ),
    );

    if (amount == null || amount <= 0) return;

    try {
      await SessionScope.of(context)
          .api
          .post('/coaches/${coach['id']}/salary', body: {'amount': amount});

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(t.t('general.saved', 'Saved.'))),
        );
      }
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);
    final canPay = SessionScope.of(context).can('accounting.create');

    return DetailScaffold(
      title: t.t('nav.coaches', 'Coaches'),
      child: _loading
          ? const LoadingState()
          : RefreshIndicator(
              onRefresh: _load,
              color: AppTheme.brand,
              backgroundColor: AppTheme.ink800,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  if (_coaches.isEmpty)
                    EmptyState(
                      icon: Icons.sports_outlined,
                      message: t.t('coaches.empty', 'No coaches yet.'),
                    ),
                  for (final coach in _coaches)
                    GlassCard(
                      margin: const EdgeInsets.only(bottom: 10),
                      padding: const EdgeInsets.all(14),
                      child: Row(
                        children: [
                          CircleAvatar(
                            radius: 22,
                            backgroundColor: Colors.white.withValues(alpha: 0.08),
                            child: Text(
                              '${coach['first_name'] ?? '?'}'.substring(0, 1),
                              style: const TextStyle(
                                color: Colors.white,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  '${coach['first_name'] ?? ''} ${coach['last_name'] ?? ''}'
                                      .trim(),
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                                const SizedBox(height: 2),
                                Text(
                                  '${coach['contract_type'] ?? ''} · '
                                  '${coach['sessions_count'] ?? 0} '
                                  '${t.t('dash.classes_today', 'sessions')}',
                                  style: Theme.of(context).textTheme.labelSmall,
                                ),
                              ],
                            ),
                          ),
                          if (canPay)
                            IconButton(
                              onPressed: () => _paySalary(coach),
                              icon: const Icon(Icons.payments_outlined, size: 20),
                              color: AppTheme.brand,
                              tooltip: t.t('coaches.pay_salary', 'Pay salary'),
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
