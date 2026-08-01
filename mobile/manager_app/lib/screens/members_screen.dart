import 'dart:async';

import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';

import 'member_detail_screen.dart';

/// The member list, with the search the desk actually uses: name, phone or
/// member number.
class MembersScreen extends StatefulWidget {
  const MembersScreen({super.key});

  @override
  State<MembersScreen> createState() => _MembersScreenState();
}

class _MembersScreenState extends State<MembersScreen> {
  final _searchController = TextEditingController();
  final _scrollController = ScrollController();

  final List<Member> _members = [];
  final Map<int, Membership?> _memberships = {};

  Timer? _debounce;
  int _page = 1;
  int _lastPage = 1;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    _scrollController.addListener(_onScroll);
    _load(reset: true);
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _searchController.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  void _onScroll() {
    final position = _scrollController.position;

    if (position.pixels > position.maxScrollExtent - 300 && !_loading && _page < _lastPage) {
      _page++;
      _load();
    }
  }

  void _onSearchChanged(String _) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), () => _load(reset: true));
  }

  Future<void> _load({bool reset = false}) async {
    if (reset) _page = 1;

    setState(() => _loading = true);

    try {
      final response = await SessionScope.of(context).api.get('/members', query: {
        'q': _searchController.text.trim().isEmpty ? null : _searchController.text.trim(),
        'page': _page,
      });

      final payload = (response as Map).cast<String, dynamic>();
      final rows = (payload['data'] as List).cast<Map>();

      setState(() {
        if (reset) {
          _members.clear();
          _memberships.clear();
        }

        _lastPage = (payload['last_page'] as num).toInt();

        for (final row in rows) {
          final json = row.cast<String, dynamic>();
          final member = Member.fromJson(json);

          _members.add(member);
          _memberships[member.id] = json['active_membership'] == null
              ? null
              : Membership.fromJson(
                  (json['active_membership'] as Map).cast<String, dynamic>(),
                  locale: TranslationsScope.of(context).locale,
                );
        }
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

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 10),
          child: TextField(
            controller: _searchController,
            onChanged: _onSearchChanged,
            textInputAction: TextInputAction.search,
            decoration: InputDecoration(
              hintText: t.t('members.search', 'Search name, phone, code…'),
              prefixIcon: const Icon(Icons.search, color: AppTheme.ink400),
            ),
          ),
        ),

        Expanded(
          child: RefreshIndicator(
            onRefresh: () => _load(reset: true),
            color: AppTheme.brand,
            backgroundColor: AppTheme.ink800,
            child: _members.isEmpty && !_loading
                ? ListView(
                    children: [
                      const SizedBox(height: 120),
                      Center(
                        child: Text(
                          t.t('members.empty', 'No members found.'),
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                      ),
                    ],
                  )
                : ListView.builder(
                    controller: _scrollController,
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 110),
                    itemCount: _members.length + (_loading ? 1 : 0),
                    itemBuilder: (context, index) {
                      if (index >= _members.length) {
                        return const Padding(
                          padding: EdgeInsets.symmetric(vertical: 20),
                          child: Center(
                            child: CircularProgressIndicator(color: AppTheme.brand),
                          ),
                        );
                      }

                      final member = _members[index];

                      return _MemberTile(
                        member: member,
                        membership: _memberships[member.id],
                        onTap: () => Navigator.of(context).push(
                          MaterialPageRoute(
                            builder: (_) => MemberDetailScreen(memberId: member.id),
                          ),
                        ),
                      );
                    },
                  ),
          ),
        ),
      ],
    );
  }
}

class _MemberTile extends StatelessWidget {
  const _MemberTile({required this.member, required this.membership, required this.onTap});

  final Member member;
  final Membership? membership;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);
    final remaining = membership?.remainingSessions;

    return GlassCard(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      onTap: onTap,
      child: Row(
        children: [
          CircleAvatar(
            radius: 22,
            backgroundColor: Colors.white.withValues(alpha: 0.08),
            child: Text(
              member.initial,
              style: const TextStyle(fontWeight: FontWeight.w700, color: Colors.white),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  member.fullName,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w600, color: Colors.white),
                ),
                const SizedBox(height: 2),
                Text(
                  '#${member.code} · ${member.phone}',
                  style: Theme.of(context).textTheme.labelSmall,
                ),
              ],
            ),
          ),
          if (membership == null)
            _StatusChip(
              label: t.t('checkin.no_membership', 'None'),
              colour: AppTheme.danger,
            )
          else
            _StatusChip(
              label: remaining != null
                  ? '$remaining'
                  : '${membership!.daysRemaining ?? '∞'} ${t.t('members.day_short', 'd')}',
              colour: AppTheme.brand,
            ),
        ],
      ),
    );
  }
}

class _StatusChip extends StatelessWidget {
  const _StatusChip({required this.label, required this.colour});

  final String label;
  final Color colour;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: colour.withValues(alpha: 0.15),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        style: TextStyle(color: colour, fontSize: 12, fontWeight: FontWeight.w700),
      ),
    );
  }
}
