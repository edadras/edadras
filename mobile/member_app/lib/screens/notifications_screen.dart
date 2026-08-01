import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';
import 'package:intl/intl.dart';

/// Renewal reminders, birthday messages and anything else the club sent.
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  List<AppNotification> _notifications = const [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);

    try {
      final response = await SessionScope.of(context).api.get('/me/notifications');

      setState(() {
        _notifications = ((response as Map)['data'] as List)
            .cast<Map>()
            .map((row) => AppNotification.fromJson(row.cast<String, dynamic>()))
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

  Future<void> _markAllRead() async {
    try {
      await SessionScope.of(context).api.post('/me/notifications/read');
      await _load();
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);
    final unread = _notifications.where((item) => item.isUnread).length;

    return DetailScaffold(
      title: t.t('members.notifications', 'Notifications'),
      actions: [
        if (unread > 0)
          TextButton(
            onPressed: _markAllRead,
            child: Text(t.t('members.mark_read', 'Mark read')),
          ),
      ],
      child: _loading
          ? const LoadingState()
          : RefreshIndicator(
              onRefresh: _load,
              color: AppTheme.brand,
              backgroundColor: AppTheme.ink800,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  if (_notifications.isEmpty)
                    EmptyState(
                      icon: Icons.notifications_none,
                      message: t.t('members.no_notifications', 'Nothing new.'),
                    ),
                  for (final notification in _notifications)
                    GlassCard(
                      margin: const EdgeInsets.only(bottom: 10),
                      padding: const EdgeInsets.all(16),
                      borderColor: notification.isUnread
                          ? AppTheme.brand.withValues(alpha: 0.45)
                          : null,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              if (notification.isUnread) ...[
                                Container(
                                  width: 7,
                                  height: 7,
                                  decoration: const BoxDecoration(
                                    color: AppTheme.brand,
                                    shape: BoxShape.circle,
                                  ),
                                ),
                                const SizedBox(width: 8),
                              ],
                              Expanded(
                                child: Text(
                                  notification.title,
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              ),
                              Text(
                                DateFormat.MMMd().format(notification.createdAt),
                                style: Theme.of(context).textTheme.labelSmall,
                              ),
                            ],
                          ),
                          const SizedBox(height: 6),
                          Text(
                            notification.body,
                            style: Theme.of(context).textTheme.bodySmall,
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
