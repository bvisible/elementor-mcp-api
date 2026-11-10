#!/bin/bash
# Install the Elementor Builder skill for Claude Code

SKILL_DIR="$HOME/.claude/skills/elementor-builder"

mkdir -p "$SKILL_DIR"
cp "$(dirname "$0")/skill.md" "$SKILL_DIR/skill.md"

echo "Elementor Builder skill installed to $SKILL_DIR"
echo "Restart Claude Code to use it."
