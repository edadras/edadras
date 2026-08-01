import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

/// The blurred colour field every glass panel sits on. Three soft green
/// pools over near black, painted once behind the whole screen.
class GradientBackdrop extends StatelessWidget {
  const GradientBackdrop({super.key, required this.child, this.accent});

  final Widget child;
  final Color? accent;

  @override
  Widget build(BuildContext context) {
    final colour = accent ?? AppTheme.brand;

    return DecoratedBox(
      decoration: const BoxDecoration(color: AppTheme.ink900),
      child: Stack(
        children: [
          Positioned(
            top: -160,
            left: -120,
            child: _Pool(colour: colour.withValues(alpha: 0.28), size: 420),
          ),
          Positioned(
            top: 40,
            right: -160,
            child: _Pool(colour: AppTheme.brandDeep.withValues(alpha: 0.18), size: 360),
          ),
          Positioned(
            bottom: -200,
            left: 40,
            child: _Pool(colour: colour.withValues(alpha: 0.12), size: 400),
          ),
          child,
        ],
      ),
    );
  }
}

class _Pool extends StatelessWidget {
  const _Pool({required this.colour, required this.size});

  final Color colour;
  final double size;

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: RadialGradient(colors: [colour, colour.withValues(alpha: 0)]),
        ),
      ),
    );
  }
}
