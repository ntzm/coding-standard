<?php declare(strict_types = 1);

namespace SlevomatCodingStandard\Sniffs\Typehints;

use SlevomatCodingStandard\Helpers\AnnotationHelper;
use SlevomatCodingStandard\Helpers\DocCommentHelper;
use SlevomatCodingStandard\Helpers\FunctionHelper;
use SlevomatCodingStandard\Helpers\NamespaceHelper;
use SlevomatCodingStandard\Helpers\PropertyHelper;
use SlevomatCodingStandard\Helpers\SniffSettingsHelper;
use SlevomatCodingStandard\Helpers\SuppressHelper;
use SlevomatCodingStandard\Helpers\TypeHintHelper;

class TypeHintDeclarationSniff implements \PHP_CodeSniffer_Sniff
{

	const NAME = 'SlevomatCodingStandard.Typehints.TypeHintDeclaration';

	const CODE_MISSING_PARAMETER_TYPE_HINT = 'MissingParameterTypeHint';

	const CODE_MISSING_PROPERTY_TYPE_HINT = 'MissingPropertyTypeHint';

	const CODE_MISSING_RETURN_TYPE_HINT = 'MissingReturnTypeHint';

	const CODE_USELESS_DOC_COMMENT = 'UselessDocComment';

	/** @var string[] */
	public $traversableTypeHints = [];

	/** @var string[] */
	public $usefulAnnotations = [];

	/** @var int[] [string => int] */
	private $normalizedTraversableTypeHints;

	/** @var int[] [string => int] */
	private $normalizedUsefulAnnotations;

	/**
	 * @phpcsSuppress SlevomatCodingStandard.Typehints.TypeHintDeclaration.MissingParameterTypeHint
	 * @param \PHP_CodeSniffer_File $phpcsFile
	 * @param int $pointer
	 */
	public function process(\PHP_CodeSniffer_File $phpcsFile, $pointer)
	{
		$token = $phpcsFile->getTokens()[$pointer];

		if ($token['code'] === T_FUNCTION) {
			$this->checkParametersTypeHints($phpcsFile, $pointer);
			$this->checkReturnTypeHints($phpcsFile, $pointer);
			$this->checkUselessDocComment($phpcsFile, $pointer);
		} elseif ($token['code'] === T_VARIABLE && PropertyHelper::isProperty($phpcsFile, $pointer)) {
			$this->checkPropertyTypeHint($phpcsFile, $pointer);
		}
	}

	/**
	 * @return int[]
	 */
	public function register(): array
	{
		return [
			T_FUNCTION,
			T_VARIABLE,
		];
	}

	private function checkParametersTypeHints(\PHP_CodeSniffer_File $phpcsFile, int $functionPointer)
	{
		if (SuppressHelper::isSniffSuppressed($phpcsFile, $functionPointer, $this->getSniffName(self::CODE_MISSING_PARAMETER_TYPE_HINT))) {
			return;
		}

		$parametersWithoutTypeHint = FunctionHelper::getParametersWithoutTypeHint($phpcsFile, $functionPointer);
		if (count($parametersWithoutTypeHint) === 0) {
			return;
		}

		$parametersTypeHintsDefinitions = [];
		foreach (FunctionHelper::getParametersAnnotations($phpcsFile, $functionPointer) as $parameterAnnotation) {
			list($parameterTypeHintDefinition, $parameterName) = preg_split('~\\s+~', $parameterAnnotation->getContent());
			$parameterName = preg_replace('~^\.{3}\\s*(\$.+)~', '\\1', $parameterName);
			$parametersTypeHintsDefinitions[$parameterName] = $parameterTypeHintDefinition;
		}

		foreach ($parametersWithoutTypeHint as $parameterName) {
			if (!isset($parametersTypeHintsDefinitions[$parameterName])) {
				$phpcsFile->addError(
					sprintf(
						'%s %s() does not have parameter type hint nor @param annotation for its parameter %s.',
						$this->getFunctionTypeLabel($phpcsFile, $functionPointer),
						FunctionHelper::getFullyQualifiedName($phpcsFile, $functionPointer),
						$parameterName
					),
					$functionPointer,
					self::CODE_MISSING_PARAMETER_TYPE_HINT
				);

				continue;
			}

			$parameterTypeHintDefinition = $parametersTypeHintsDefinitions[$parameterName];

			if ($this->definitionContainsMixedTypeHint($parameterTypeHintDefinition) || strtolower($parameterTypeHintDefinition) === 'null') {
				continue;
			}

			if ($this->definitionContainsOneTypeHint($parameterTypeHintDefinition)) {
				$phpcsFile->addError(
					sprintf(
						'%s %s() does not have parameter type hint for its parameter %s but it should be possible to add it based on @param annotation "%s".',
						$this->getFunctionTypeLabel($phpcsFile, $functionPointer),
						FunctionHelper::getFullyQualifiedName($phpcsFile, $functionPointer),
						$parameterName,
						$parameterTypeHintDefinition
					),
					$functionPointer,
					self::CODE_MISSING_PARAMETER_TYPE_HINT
				);
			} elseif ($this->definitionContainsJustTwoTypeHints($parameterTypeHintDefinition)) {
				$parameterTypeHints = explode('|', $parameterTypeHintDefinition);
				if (strtolower($parameterTypeHints[0]) === 'null' || strtolower($parameterTypeHints[1]) === 'null') {
					$parameterTypeHint = strtolower($parameterTypeHints[0]) === 'null' ? $parameterTypeHints[1] : $parameterTypeHints[0];
					if ($this->definitionContainsOneTypeHint($parameterTypeHint)) {
						$phpcsFile->addError(
							sprintf(
								'%s %s() does not have parameter type hint for its parameter %s but it should be possible to add it based on @param annotation "%s".',
								$this->getFunctionTypeLabel($phpcsFile, $functionPointer),
								FunctionHelper::getFullyQualifiedName($phpcsFile, $functionPointer),
								$parameterName,
								$parameterTypeHintDefinition
							),
							$functionPointer,
							self::CODE_MISSING_PARAMETER_TYPE_HINT
						);
					}
				} elseif ($this->definitionContainsTraversableTypeHint($phpcsFile, $functionPointer, $parameterTypeHintDefinition)) {
					$phpcsFile->addError(
						sprintf(
							'%s %s() does not have parameter type hint for its parameter %s but it should be possible to add it based on @param annotation "%s".',
							$this->getFunctionTypeLabel($phpcsFile, $functionPointer),
							FunctionHelper::getFullyQualifiedName($phpcsFile, $functionPointer),
							$parameterName,
							$parameterTypeHintDefinition
						),
						$functionPointer,
						self::CODE_MISSING_PARAMETER_TYPE_HINT
					);
				}
			}
		}
	}

	private function checkReturnTypeHints(\PHP_CodeSniffer_File $phpcsFile, int $functionPointer)
	{
		if (SuppressHelper::isSniffSuppressed($phpcsFile, $functionPointer, $this->getSniffName(self::CODE_MISSING_RETURN_TYPE_HINT))) {
			return;
		}

		if (FunctionHelper::isAbstract($phpcsFile, $functionPointer)) {
			return;
		}

		if (FunctionHelper::hasReturnTypeHint($phpcsFile, $functionPointer)) {
			return;
		}

		$returnsValue = FunctionHelper::returnsValue($phpcsFile, $functionPointer);
		$returnsVoid = FunctionHelper::returnsVoid($phpcsFile, $functionPointer);

		$returnAnnotation = FunctionHelper::findReturnAnnotation($phpcsFile, $functionPointer);
		if ($returnAnnotation === null || $returnAnnotation->getContent() === null) {
			if ($returnsValue) {
				$phpcsFile->addError(
					sprintf(
						'%s %s() does not have return type hint nor @return annotation for its return value.',
						$this->getFunctionTypeLabel($phpcsFile, $functionPointer),
						FunctionHelper::getFullyQualifiedName($phpcsFile, $functionPointer)
					),
					$functionPointer,
					self::CODE_MISSING_RETURN_TYPE_HINT
				);
			}

			return;
		}

		$returnTypeHintDefinition = preg_split('~\\s+~', $returnAnnotation->getContent())[0];
		if ($this->definitionContainsVoidTypeHint($returnTypeHintDefinition) && $returnsVoid) {
			$phpcsFile->addError(
				sprintf(
					'%s %s() does not have return type hint for its return value but it should be possible to add it based on @return annotation "%s".',
					$this->getFunctionTypeLabel($phpcsFile, $functionPointer),
					FunctionHelper::getFullyQualifiedName($phpcsFile, $functionPointer),
					$returnTypeHintDefinition
				),
				$functionPointer,
				self::CODE_MISSING_RETURN_TYPE_HINT
			);
			return;
		}

		if (!$returnsValue) {
			return;
		}

		if ($this->definitionContainsMixedTypeHint($returnTypeHintDefinition)) {
			return;
		} elseif ($this->definitionContainsNullTypeHint($returnTypeHintDefinition)) {
			return;
		} elseif ($this->definitionContainsOneTypeHint($returnTypeHintDefinition)) {
			$phpcsFile->addError(
				sprintf(
					'%s %s() does not have return type hint for its return value but it should be possible to add it based on @return annotation "%s".',
					$this->getFunctionTypeLabel($phpcsFile, $functionPointer),
					FunctionHelper::getFullyQualifiedName($phpcsFile, $functionPointer),
					$returnTypeHintDefinition
				),
				$functionPointer,
				self::CODE_MISSING_RETURN_TYPE_HINT
			);
		} elseif ($this->definitionContainsJustTwoTypeHints($returnTypeHintDefinition) && $this->definitionContainsTraversableTypeHint($phpcsFile, $functionPointer, $returnTypeHintDefinition)) {
			$phpcsFile->addError(
				sprintf(
					'%s %s() does not have return type hint for its return value but it should be possible to add it based on @return annotation "%s".',
					$this->getFunctionTypeLabel($phpcsFile, $functionPointer),
					FunctionHelper::getFullyQualifiedName($phpcsFile, $functionPointer),
					$returnTypeHintDefinition
				),
				$functionPointer,
				self::CODE_MISSING_RETURN_TYPE_HINT
			);
		}
	}

	private function checkUselessDocComment(\PHP_CodeSniffer_File $phpcsFile, int $functionPointer)
	{
		if (SuppressHelper::isSniffSuppressed($phpcsFile, $functionPointer, $this->getSniffName(self::CODE_USELESS_DOC_COMMENT))) {
			return;
		}

		if (!DocCommentHelper::hasDocComment($phpcsFile, $functionPointer)) {
			return;
		}

		if (DocCommentHelper::hasDocCommentDescription($phpcsFile, $functionPointer)) {
			return;
		}

		$returnTypeHint = FunctionHelper::findReturnTypeHint($phpcsFile, $functionPointer);
		if (FunctionHelper::isAbstract($phpcsFile, $functionPointer)) {
			$returnAnnotation = FunctionHelper::findReturnAnnotation($phpcsFile, $functionPointer);
			if (
				($returnTypeHint !== null && $this->isTraversableTypeHint(TypeHintHelper::getFullyQualifiedTypeHint($phpcsFile, $functionPointer, $returnTypeHint)))
				|| ($returnAnnotation !== null && ($this->definitionContainsMixedTypeHint($returnAnnotation->getContent()) || $this->definitionContainsNullTypeHint($returnAnnotation->getContent()) || !$this->definitionContainsOneTypeHint($returnAnnotation->getContent())))
			) {
				return;
			}
		} elseif (FunctionHelper::returnsValue($phpcsFile, $functionPointer)) {
			$returnAnnotation = FunctionHelper::findReturnAnnotation($phpcsFile, $functionPointer);
			if ($returnTypeHint === null || $this->isTraversableTypeHint(TypeHintHelper::getFullyQualifiedTypeHint($phpcsFile, $functionPointer, $returnTypeHint))) {
				return;
			}

			if ($returnAnnotation !== null) {
				if (!$this->definitionContainsOneTypeHint($returnAnnotation->getContent())) {
					return;
				} elseif (TypeHintHelper::getFullyQualifiedTypeHint($phpcsFile, $functionPointer, $returnTypeHint) === 'self' && $this->definitionContainsStaticOrThisTypeHint($returnAnnotation->getContent())) {
					return;
				}
			}
		}

		foreach (FunctionHelper::getParametersTypeHints($phpcsFile, $functionPointer) as $parameterTypeHint) {
			if ($parameterTypeHint === null || $this->isTraversableTypeHint(TypeHintHelper::getFullyQualifiedTypeHint($phpcsFile, $functionPointer, $parameterTypeHint))) {
				return;
			}
		}

		foreach (array_keys(AnnotationHelper::getAnnotations($phpcsFile, $functionPointer)) as $annotationName) {
			if ($annotationName === SuppressHelper::ANNOTATION || array_key_exists($annotationName, $this->getNormalizedUsefulAnnotations())) {
				return;
			}
		}

		$phpcsFile->addError(
			sprintf(
				'%s %s() does not need documentation comment.',
				$this->getFunctionTypeLabel($phpcsFile, $functionPointer),
				FunctionHelper::getFullyQualifiedName($phpcsFile, $functionPointer)
			),
			$functionPointer,
			self::CODE_USELESS_DOC_COMMENT
		);
	}

	private function checkPropertyTypeHint(\PHP_CodeSniffer_File $phpcsFile, int $propertyPointer)
	{
		$varAnnotations = AnnotationHelper::getAnnotationsByName($phpcsFile, $propertyPointer, '@var');
		if (count($varAnnotations) === 0) {
			$phpcsFile->addError(
				sprintf(
					'Property %s does not have @var annotation.',
					PropertyHelper::getFullyQualifiedName($phpcsFile, $propertyPointer)
				),
				$propertyPointer,
				self::CODE_MISSING_PROPERTY_TYPE_HINT
			);
		}
	}

	private function getSniffName(string $sniffName): string
	{
		return sprintf('%s.%s', self::NAME, $sniffName);
	}

	private function definitionContainsMixedTypeHint(string $typeHintDefinition): bool
	{
		return preg_match('~(?:^mixed(?:\[\])?$)|(?:^mixed(?:\[\])?\|)|(?:\|mixed(?:\[\])?\|)|(?:\|mixed(?:\[\])?$)~i', $typeHintDefinition) !== 0;
	}

	private function definitionContainsNullTypeHint(string $typeHintDefinition): bool
	{
		return preg_match('~(?:^null$)|(?:^null\|)|(?:\|null\|)|(?:\|null$)~i', $typeHintDefinition) !== 0;
	}

	private function definitionContainsVoidTypeHint(string $typeHintDefinition): bool
	{
		return preg_match('~(?:^void)|(?:^void\|)|(?:\|void\|)|(?:\|void)~i', $typeHintDefinition) !== 0;
	}

	private function definitionContainsStaticOrThisTypeHint(string $typeHintDefinition): bool
	{
		return preg_match('~(?:^static$)|(?:^static\|)|(?:\|static\|)|(?:\|static$)~i', $typeHintDefinition) !== 0
			|| preg_match('~(?:^\$this$)|(?:^\$this\|)|(?:\|\$this\|)|(?:\|\$this$)~i', $typeHintDefinition) !== 0;
	}

	private function definitionContainsOneTypeHint(string $typeHintDefinition): bool
	{
		return strpos($typeHintDefinition, '|') === false;
	}

	private function definitionContainsJustTwoTypeHints(string $typeHintDefinition): bool
	{
		return count(explode('|', $typeHintDefinition)) === 2;
	}

	private function definitionContainsTraversableTypeHint(\PHP_CodeSniffer_File $phpcsFile, int $functionPointer, string $typeHintDefinition): bool
	{
		if (!preg_match('~\[\](?:\||$)~', $typeHintDefinition)) {
			return false;
		}

		return array_reduce(explode('|', $typeHintDefinition), function (bool $carry, string $typeHint) use ($phpcsFile, $functionPointer): bool {
			$fullyQualifiedTypeHint = TypeHintHelper::getFullyQualifiedTypeHint($phpcsFile, $functionPointer, $typeHint);
			if ($this->isTraversableTypeHint($fullyQualifiedTypeHint)) {
				$carry = true;
			}
			return $carry;
		}, false);
	}

	private function isTraversableTypeHint(string $typeHint): bool
	{
		return in_array(strtolower($typeHint), ['array', 'iterable'], true) || array_key_exists($typeHint, $this->getNormalizedTraversableTypeHints());
	}

	/**
	 * @return int[] [string => int]
	 */
	private function getNormalizedTraversableTypeHints(): array
	{
		if ($this->normalizedTraversableTypeHints === null) {
			$this->normalizedTraversableTypeHints = array_flip(array_map(function (string $typeHint): string {
				return NamespaceHelper::isFullyQualifiedName($typeHint) ? $typeHint : sprintf('%s%s', NamespaceHelper::NAMESPACE_SEPARATOR, $typeHint);
			}, SniffSettingsHelper::normalizeArray($this->traversableTypeHints)));
		}
		return $this->normalizedTraversableTypeHints;
	}

	/**
	 * @return int[] [string => int]
	 */
	private function getNormalizedUsefulAnnotations(): array
	{
		if ($this->normalizedUsefulAnnotations === null) {
			$this->normalizedUsefulAnnotations = array_flip(SniffSettingsHelper::normalizeArray($this->usefulAnnotations));
		}
		return $this->normalizedUsefulAnnotations;
	}

	private function getFunctionTypeLabel(\PHP_CodeSniffer_File $phpcsFile, int $functionPointer): string
	{
		return FunctionHelper::isMethod($phpcsFile, $functionPointer) ? 'Method' : 'Function';
	}

}