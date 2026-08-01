import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';
import 'package:intl/intl.dart';

/// The smart module on a phone: who is drifting away, what the month is
/// heading for, and the assistant that answers questions about the club.
class AiScreen extends StatefulWidget {
  const AiScreen({super.key});

  @override
  State<AiScreen> createState() => _AiScreenState();
}

class _AiScreenState extends State<AiScreen> {
  Map<String, dynamic>? _overview;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);

    try {
      final response = await SessionScope.of(context).api.get('/ai/overview');
      setState(() => _overview = (response as Map).cast<String, dynamic>());
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

    if (_loading && _overview == null) {
      return const Center(child: CircularProgressIndicator(color: AppTheme.brand));
    }

    final forecast = (_overview?['forecast'] as Map?)?.cast<String, dynamic>() ?? const {};
    final attendance = (_overview?['attendance'] as Map?)?.cast<String, dynamic>() ?? const {};
    final risks = ((_overview?['churn_risk'] as List?) ?? const []).cast<Map>();
    final campaigns = ((_overview?['campaign_suggestions'] as List?) ?? const []).cast<Map>();

    return RefreshIndicator(
      onRefresh: _load,
      color: AppTheme.brand,
      backgroundColor: AppTheme.ink800,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 110),
        children: [
          Text(
            '✨ ${t.t('ai.title', 'Smart insights')}',
            style: Theme.of(context).textTheme.headlineSmall,
          ),
          const SizedBox(height: 16),

          Row(
            children: [
              Expanded(
                child: StatTile(
                  label: t.t('ai.projected', 'Projected this month'),
                  value: double.tryParse('${forecast['projected_total']}') ?? 0,
                  icon: '🔮',
                  money: true,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: StatTile(
                  label: t.t('ai.peak_hour', 'Peak hour'),
                  value: int.tryParse('${attendance['peak_hour']}') ?? 0,
                  icon: '⏰',
                  hint: '${attendance['peak_hour_visits'] ?? 0} ${t.t('ai.visits', 'visits')}',
                ),
              ),
            ],
          ),

          const SizedBox(height: 16),
          GlassSection(
            title: t.t('ai.churn_risk', 'Members at risk'),
            subtitle: t.t('ai.churn_hint', 'Ranked by how likely they are to stop coming'),
            child: risks.isEmpty
                ? Text(
                    t.t('ai.no_risk', 'Nobody looks at risk right now.'),
                    style: Theme.of(context).textTheme.bodySmall,
                  )
                : Column(
                    children: [
                      for (final raw in risks) _RiskRow(risk: raw.cast<String, dynamic>()),
                    ],
                  ),
          ),

          const SizedBox(height: 16),
          GlassSection(
            title: t.t('ai.campaigns', 'Suggested campaigns'),
            child: campaigns.isEmpty
                ? Text(
                    t.t('ai.no_campaigns', 'No campaign is worth sending today.'),
                    style: Theme.of(context).textTheme.bodySmall,
                  )
                : Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      for (final raw in campaigns)
                        _CampaignRow(campaign: raw.cast<String, dynamic>()),
                    ],
                  ),
          ),

          if (SessionScope.of(context).can('ai.assistant')) ...[
            const SizedBox(height: 16),
            BrandButton(
              label: t.t('ai.assistant', 'Ask the assistant'),
              icon: Icons.chat_bubble_outline,
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => const AssistantScreen()),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _RiskRow extends StatelessWidget {
  const _RiskRow({required this.risk});

  final Map<String, dynamic> risk;

  @override
  Widget build(BuildContext context) {
    final member = (risk['member'] as Map).cast<String, dynamic>();
    final score = double.tryParse('${risk['score']}') ?? 0;
    final critical = risk['severity'] == 'critical';

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  '${member['first_name']} ${member['last_name']}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600),
                ),
              ),
              Text(
                score.toStringAsFixed(0),
                style: TextStyle(
                  color: critical ? AppTheme.danger : AppTheme.warning,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          ClipRRect(
            borderRadius: BorderRadius.circular(999),
            child: LinearProgressIndicator(
              value: score / 100,
              minHeight: 6,
              backgroundColor: Colors.white.withValues(alpha: 0.08),
              color: critical ? AppTheme.danger : AppTheme.warning,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            ((risk['reasons'] as List?) ?? const []).join(' '),
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}

class _CampaignRow extends StatelessWidget {
  const _CampaignRow({required this.campaign});

  final Map<String, dynamic> campaign;

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  '${campaign['title']}',
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600),
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: AppTheme.brand.withValues(alpha: 0.15),
                  borderRadius: BorderRadius.circular(999),
                ),
                child: Text(
                  '${campaign['channel']}',
                  style: const TextStyle(color: AppTheme.brand, fontSize: 11),
                ),
              ),
            ],
          ),
          const SizedBox(height: 4),
          Text('${campaign['body']}', style: Theme.of(context).textTheme.bodySmall),
          const SizedBox(height: 4),
          Text(
            '${campaign['recipients']} ${t.t('ai.recipients', 'recipients')}',
            style: Theme.of(context).textTheme.labelSmall,
          ),
        ],
      ),
    );
  }
}

/// The management chat assistant.
class AssistantScreen extends StatefulWidget {
  const AssistantScreen({super.key});

  @override
  State<AssistantScreen> createState() => _AssistantScreenState();
}

class _AssistantScreenState extends State<AssistantScreen> {
  final _controller = TextEditingController();
  final _scrollController = ScrollController();
  final List<({String role, String content})> _turns = [];

  bool _thinking = false;

  @override
  void dispose() {
    _controller.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  Future<void> _ask() async {
    final question = _controller.text.trim();

    if (question.isEmpty || _thinking) return;

    setState(() {
      _turns.add((role: 'user', content: question));
      _thinking = true;
    });

    _controller.clear();
    _scrollToEnd();

    try {
      final response = await SessionScope.of(context).api.post('/ai/chat', body: {
        'question': question,
        // The API caps the history it accepts; the last few turns are what
        // keeps the conversation coherent anyway.
        'history': _turns
            .take(_turns.length - 1)
            .toList()
            .reversed
            .take(10)
            .toList()
            .reversed
            .map((turn) => {'role': turn.role, 'content': turn.content})
            .toList(),
      });

      final answer = '${(response as Map)['answer'] ?? ''}';
      setState(() => _turns.add((role: 'assistant', content: answer)));
    } on ApiException catch (error) {
      setState(() => _turns.add((role: 'assistant', content: error.message)));
    } finally {
      if (mounted) setState(() => _thinking = false);
      _scrollToEnd();
    }
  }

  void _scrollToEnd() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!_scrollController.hasClients) return;

      _scrollController.animateTo(
        _scrollController.position.maxScrollExtent,
        duration: const Duration(milliseconds: 260),
        curve: Curves.easeOut,
      );
    });
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    return Scaffold(
      appBar: AppBar(title: Text(t.t('ai.assistant', 'Management assistant'))),
      body: GradientBackdrop(
        child: SafeArea(
          child: Column(
            children: [
              Expanded(
                child: _turns.isEmpty
                    ? Center(
                        child: Padding(
                          padding: const EdgeInsets.all(32),
                          child: Text(
                            t.t('ai.assistant_empty', 'Ask anything about this club.'),
                            textAlign: TextAlign.center,
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ),
                      )
                    : ListView.builder(
                        controller: _scrollController,
                        padding: const EdgeInsets.all(16),
                        itemCount: _turns.length,
                        itemBuilder: (context, index) => _Bubble(turn: _turns[index]),
                      ),
              ),

              if (_thinking)
                const Padding(
                  padding: EdgeInsets.only(bottom: 8),
                  child: LinearProgressIndicator(
                    minHeight: 2,
                    color: AppTheme.brand,
                    backgroundColor: Colors.transparent,
                  ),
                ),

              Padding(
                padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
                child: Row(
                  children: [
                    Expanded(
                      child: TextField(
                        controller: _controller,
                        onSubmitted: (_) => _ask(),
                        decoration: InputDecoration(
                          hintText: t.t('ai.ask_placeholder', 'Ask a question…'),
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    IconButton.filled(
                      onPressed: _thinking ? null : _ask,
                      icon: const Icon(Icons.send_rounded),
                      style: IconButton.styleFrom(
                        backgroundColor: AppTheme.brand,
                        foregroundColor: AppTheme.ink900,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Bubble extends StatelessWidget {
  const _Bubble({required this.turn});

  final ({String role, String content}) turn;

  @override
  Widget build(BuildContext context) {
    final isUser = turn.role == 'user';

    return Align(
      alignment: isUser ? AlignmentDirectional.centerEnd : AlignmentDirectional.centerStart,
      child: Container(
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        constraints: BoxConstraints(maxWidth: MediaQuery.sizeOf(context).width * 0.78),
        decoration: BoxDecoration(
          color: isUser ? AppTheme.brand : Colors.white.withValues(alpha: 0.08),
          borderRadius: BorderRadius.circular(18),
        ),
        child: Text(
          turn.content,
          style: TextStyle(color: isUser ? AppTheme.ink900 : Colors.white, height: 1.45),
        ),
      ),
    );
  }
}

/// Formats an amount the way the club's currency reads.
String formatMoney(num value) => NumberFormat.decimalPattern().format(value.round());
