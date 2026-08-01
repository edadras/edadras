import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';
import 'package:intl/intl.dart';

/// One member's card: who they are, what pass they hold, what the club
/// needs to know before they train, and the two actions the desk needs.
class MemberDetailScreen extends StatefulWidget {
  const MemberDetailScreen({super.key, required this.memberId});

  final int memberId;

  @override
  State<MemberDetailScreen> createState() => _MemberDetailScreenState();
}

class _MemberDetailScreenState extends State<MemberDetailScreen> {
  Member? _member;
  Membership? _membership;
  List<Map<String, dynamic>> _plans = const [];
  bool _loading = true;
  bool _selling = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final api = SessionScope.of(context).api;
    final locale = TranslationsScope.of(context).locale;

    setState(() => _loading = true);

    try {
      final response = await api.get('/members/${widget.memberId}');
      final json = (response as Map).cast<String, dynamic>();

      List<Map<String, dynamic>> plans = const [];

      if (SessionScope.of(context).can('memberships.create')) {
        final planResponse = await api.get('/membership-plans');
        plans = (planResponse as List).cast<Map>().map((p) => p.cast<String, dynamic>()).toList();
      }

      setState(() {
        _member = Member.fromJson(json);
        _membership = json['active_membership'] == null
            ? null
            : Membership.fromJson(
                (json['active_membership'] as Map).cast<String, dynamic>(),
                locale: locale,
              );
        _plans = plans;
      });
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _sell(int planId) async {
    setState(() => _selling = true);

    try {
      await SessionScope.of(context).api.post('/memberships', body: {
        'member_id': widget.memberId,
        'membership_plan_id': planId,
      });

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(TranslationsScope.of(context).t('general.saved', 'Saved.'))),
        );
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

  Future<void> _openPlanSheet() async {
    final t = TranslationsScope.of(context);

    await showModalBottomSheet<void>(
      context: context,
      backgroundColor: AppTheme.ink800,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (sheetContext) => SafeArea(
        child: ListView(
          shrinkWrap: true,
          padding: const EdgeInsets.all(16),
          children: [
            Text(
              t.t('members.choose_plan', 'Choose a plan'),
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 12),
            for (final plan in _plans)
              ListTile(
                title: Text(
                  '${(plan['name'] as Map?)?[t.locale] ?? (plan['name'] as Map?)?['en'] ?? ''}',
                  style: const TextStyle(color: Colors.white),
                ),
                subtitle: Text(
                  NumberFormat.decimalPattern().format(
                    double.tryParse('${plan['price']}')?.round() ?? 0,
                  ),
                  style: Theme.of(context).textTheme.labelSmall,
                ),
                trailing: const Icon(Icons.chevron_right, color: AppTheme.ink400),
                onTap: () {
                  Navigator.of(sheetContext).pop();
                  _sell(plan['id'] as int);
                },
              ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    return Scaffold(
      body: GradientBackdrop(
        child: SafeArea(
          child: _loading && _member == null
              ? const Center(child: CircularProgressIndicator(color: AppTheme.brand))
              : ListView(
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
                  children: [
                    Row(
                      children: [
                        IconButton(
                          onPressed: () => Navigator.of(context).pop(),
                          icon: const Icon(Icons.arrow_back),
                        ),
                        Expanded(
                          child: Text(
                            _member?.fullName ?? '',
                            style: Theme.of(context).textTheme.titleMedium,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),

                    GlassCard(
                      child: Row(
                        children: [
                          CircleAvatar(
                            radius: 30,
                            backgroundColor: Colors.white.withValues(alpha: 0.08),
                            child: Text(
                              _member?.initial ?? '?',
                              style: const TextStyle(
                                fontSize: 22,
                                fontWeight: FontWeight.w800,
                                color: Colors.white,
                              ),
                            ),
                          ),
                          const SizedBox(width: 14),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  _member?.fullName ?? '',
                                  style: Theme.of(context).textTheme.titleMedium,
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  '#${_member?.code} · ${_member?.phone}',
                                  style: Theme.of(context).textTheme.labelSmall,
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),

                    // Medical flags are the one thing a coach must see before
                    // the member steps onto the floor.
                    if ((_member?.medicalFlags ?? const []).isNotEmpty) ...[
                      const SizedBox(height: 12),
                      GlassCard(
                        borderColor: AppTheme.warning.withValues(alpha: 0.5),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            for (final flag in _member!.medicalFlags)
                              Padding(
                                padding: const EdgeInsets.symmetric(vertical: 3),
                                child: Row(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    const Icon(Icons.warning_amber_rounded,
                                        color: AppTheme.warning, size: 18),
                                    const SizedBox(width: 8),
                                    Expanded(
                                      child: Text(flag,
                                          style: Theme.of(context).textTheme.bodySmall),
                                    ),
                                  ],
                                ),
                              ),
                          ],
                        ),
                      ),
                    ],

                    const SizedBox(height: 12),
                    GlassSection(
                      title: t.t('members.membership', 'Membership'),
                      child: _membership == null
                          ? Text(
                              t.t('checkin.no_membership', 'No active membership.'),
                              style: Theme.of(context).textTheme.bodySmall,
                            )
                          : Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  _membership!.planName ?? _membership!.type,
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                                const SizedBox(height: 10),
                                ClipRRect(
                                  borderRadius: BorderRadius.circular(999),
                                  child: LinearProgressIndicator(
                                    value: _membership!.progress,
                                    minHeight: 8,
                                    backgroundColor: Colors.white.withValues(alpha: 0.08),
                                    color: AppTheme.brand,
                                  ),
                                ),
                                const SizedBox(height: 10),
                                Text(
                                  [
                                    if (_membership!.remainingSessions != null)
                                      '${t.t('checkin.sessions_left', 'Sessions left')}: ${_membership!.remainingSessions}',
                                    if (_membership!.daysRemaining != null)
                                      '${t.t('checkin.days_left', 'Days left')}: ${_membership!.daysRemaining}',
                                  ].join(' · '),
                                  style: Theme.of(context).textTheme.bodySmall,
                                ),
                              ],
                            ),
                    ),

                    if (_plans.isNotEmpty) ...[
                      const SizedBox(height: 16),
                      BrandButton(
                        label: t.t('members.sell', 'Sell a membership'),
                        icon: Icons.add_card,
                        loading: _selling,
                        onPressed: _openPlanSheet,
                      ),
                    ],
                  ],
                ),
        ),
      ),
    );
  }
}
