import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';
import 'package:intl/intl.dart';
import 'package:qr_flutter/qr_flutter.dart';

/// The member's home: the badge they hold up at the door, what is left on
/// their pass, and their recent visits.
class MembershipScreen extends StatefulWidget {
  const MembershipScreen({super.key});

  @override
  State<MembershipScreen> createState() => _MembershipScreenState();
}

class _MembershipScreenState extends State<MembershipScreen> {
  Map<String, dynamic>? _data;
  List<Attendance> _history = const [];
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
      final dashboard = await api.get('/me/dashboard');
      final history = await api.get('/me/attendance', query: {'per_page': 10});

      setState(() {
        _data = (dashboard as Map).cast<String, dynamic>();
        _history = ((history as Map)['data'] as List)
            .cast<Map>()
            .map((row) => Attendance.fromJson(row.cast<String, dynamic>()))
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

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);
    final session = SessionScope.of(context);

    if (_loading && _data == null) {
      return const Center(child: CircularProgressIndicator(color: AppTheme.brand));
    }

    final member = (_data?['member'] as Map?)?.cast<String, dynamic>() ?? const {};
    final qrToken = '${_data?['qr_token'] ?? ''}';
    final remaining = _data?['remaining_sessions'];
    final daysRemaining = _data?['days_remaining'];
    final wallet = double.tryParse('${_data?['wallet_balance']}') ?? 0;

    return RefreshIndicator(
      onRefresh: _load,
      color: AppTheme.brand,
      backgroundColor: AppTheme.ink800,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 110),
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${member['first_name'] ?? ''} ${member['last_name'] ?? ''}'.trim(),
                      style: Theme.of(context).textTheme.headlineSmall,
                    ),
                    Text(
                      session.club?.name ?? '',
                      style: Theme.of(context).textTheme.labelSmall,
                    ),
                  ],
                ),
              ),
              IconButton(
                onPressed: session.signOut,
                icon: const Icon(Icons.logout, color: AppTheme.ink400),
                tooltip: t.t('nav.sign_out', 'Sign out'),
              ),
            ],
          ),
          const SizedBox(height: 16),

          // The badge is rendered on the device, so the door works even when
          // the phone has no signal.
          GlassCard(
            strong: true,
            padding: const EdgeInsets.all(22),
            child: Column(
              children: [
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(18),
                  ),
                  child: QrImageView(
                    data: qrToken,
                    version: QrVersions.auto,
                    size: 208,
                    gapless: true,
                  ),
                ),
                const SizedBox(height: 14),
                Text(
                  '#${member['code'] ?? ''}',
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 2,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  t.t('checkin.scan_hint', 'Show this at the door'),
                  style: Theme.of(context).textTheme.labelSmall,
                ),
              ],
            ),
          ),

          const SizedBox(height: 16),
          Row(
            children: [
              Expanded(
                child: StatTile(
                  label: t.t('checkin.sessions_left', 'Sessions left'),
                  value: remaining is num ? remaining : 0,
                  icon: '🔢',
                  hint: remaining == null ? '∞' : null,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: StatTile(
                  label: t.t('checkin.days_left', 'Days left'),
                  value: daysRemaining is num ? daysRemaining : 0,
                  icon: '📅',
                ),
              ),
            ],
          ),

          const SizedBox(height: 12),
          StatTile(
            label: t.t('members.wallet', 'Wallet'),
            value: wallet,
            icon: '👛',
            money: true,
          ),

          const SizedBox(height: 16),
          GlassSection(
            title: t.t('checkin.recent_visits', 'Recent visits'),
            child: _history.isEmpty
                ? Text(
                    t.t('checkin.no_entries', 'No visits recorded yet.'),
                    style: Theme.of(context).textTheme.bodySmall,
                  )
                : Column(
                    children: [
                      for (final visit in _history)
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 6),
                          child: Row(
                            children: [
                              const Icon(Icons.check_circle_outline,
                                  size: 18, color: AppTheme.brand),
                              const SizedBox(width: 10),
                              Expanded(
                                child: Text(
                                  DateFormat.yMMMd().add_Hm().format(visit.checkedInAt),
                                  style: Theme.of(context).textTheme.bodySmall,
                                ),
                              ),
                              if (visit.duration != null)
                                Text(
                                  '${visit.duration!.inMinutes}′',
                                  style: Theme.of(context).textTheme.labelSmall,
                                ),
                            ],
                          ),
                        ),
                    ],
                  ),
          ),
        ],
      ),
    );
  }
}
