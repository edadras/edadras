import 'dart:ui';

import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

/// The frosted panel the whole interface is built from: a translucent fill
/// over a real backdrop blur, with a hairline highlight along the top edge.
class GlassCard extends StatelessWidget {
  const GlassCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(18),
    this.margin = EdgeInsets.zero,
    this.radius = AppTheme.glassRadius,
    this.onTap,
    this.borderColor,
    this.strong = false,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final EdgeInsetsGeometry margin;
  final double radius;
  final VoidCallback? onTap;
  final Color? borderColor;
  final bool strong;

  @override
  Widget build(BuildContext context) {
    final shape = BorderRadius.circular(radius);

    return Padding(
      padding: margin,
      child: ClipRRect(
        borderRadius: shape,
        child: BackdropFilter(
          filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
          child: DecoratedBox(
            decoration: BoxDecoration(
              color: AppTheme.glassFill(strong ? 0.10 : 0.06),
              borderRadius: shape,
              border: Border.all(
                color: borderColor ?? Colors.white.withValues(alpha: strong ? 0.16 : 0.10),
              ),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withValues(alpha: 0.45),
                  blurRadius: 30,
                  offset: const Offset(0, 16),
                ),
              ],
            ),
            child: Material(
              color: Colors.transparent,
              child: InkWell(
                onTap: onTap,
                borderRadius: shape,
                child: Padding(padding: padding, child: child),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// A glass panel with a title row, used wherever a section needs a heading.
class GlassSection extends StatelessWidget {
  const GlassSection({
    super.key,
    required this.title,
    required this.child,
    this.subtitle,
    this.trailing,
    this.margin = EdgeInsets.zero,
  });

  final String title;
  final String? subtitle;
  final Widget child;
  final Widget? trailing;
  final EdgeInsetsGeometry margin;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      margin: margin,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title, style: Theme.of(context).textTheme.titleMedium),
                    if (subtitle != null)
                      Padding(
                        padding: const EdgeInsets.only(top: 2),
                        child: Text(subtitle!, style: Theme.of(context).textTheme.labelSmall),
                      ),
                  ],
                ),
              ),
              if (trailing != null) trailing!,
            ],
          ),
          const SizedBox(height: 14),
          child,
        ],
      ),
    );
  }
}
